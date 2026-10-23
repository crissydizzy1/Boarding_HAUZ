<?php

function getCurrentMonthPaymentDue(
    mysqli $conn,
    int $tenantId,
    float $monthlyRate,
    ?string $moveInDate = null,
    ?DateTimeImmutable $today = null,
    int $leaseId = 0
): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $billingDay = 1;
    $parsedMoveInDate = null;
    if ($moveInDate !== null && $moveInDate !== '') {
        $parsedMoveInDate = DateTimeImmutable::createFromFormat('!Y-m-d', $moveInDate) ?: null;
        if ($parsedMoveInDate) {
            $billingDay = (int) $parsedMoveInDate->format('j');
        }
    }

    $makeCycleDueDate = static function (DateTimeImmutable $month, int $day): DateTimeImmutable {
        $day = min($day, (int) $month->format('t'));
        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day);
    };

    $dueDate = $makeCycleDueDate($today, $billingDay);
    if ($parsedMoveInDate && $parsedMoveInDate > $today && $dueDate < $parsedMoveInDate) {
        $dueDate = $makeCycleDueDate($parsedMoveInDate, $billingDay);
    }

    $summary = [
        'due_date' => $dueDate,
        'amount_due' => $monthlyRate,
        'status' => 'Pending',
        'has_pending_submission' => false,
    ];

    if ($tenantId <= 0 || $monthlyRate <= 0) {
        return $summary;
    }

    $paymentScope = $leaseId > 0 ? 'lease_id = ?' : 'tenant_id = ?';
    $scopeId = $leaseId > 0 ? $leaseId : $tenantId;
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(status IN ('Approved', 'Verified', 'Paid')), 0) AS confirmed_count,
            COALESCE(SUM(status IN ('Pending Verification', 'Pending')), 0) AS pending_count
        FROM payments
        WHERE {$paymentScope}
          AND payment_date >= ?
          AND payment_date < ?
    ");
    if (!$stmt) {
        throw new mysqli_sql_exception($conn->error);
    }

    $getCyclePaymentCounts = static function (DateTimeImmutable $cycleDate) use ($stmt, $scopeId): array {
        $monthStart = $cycleDate->modify('first day of this month')->format('Y-m-d');
        $nextMonthStart = $cycleDate->modify('first day of next month')->format('Y-m-d');
        $stmt->bind_param('iss', $scopeId, $monthStart, $nextMonthStart);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: [];
    };

    $paymentCounts = $getCyclePaymentCounts($dueDate);
    $advancedToNextCycle = false;
    if ((int) ($paymentCounts['confirmed_count'] ?? 0) > 0) {
        $dueDate = $makeCycleDueDate($dueDate->modify('first day of next month'), $billingDay);
        $paymentCounts = $getCyclePaymentCounts($dueDate);
        $advancedToNextCycle = true;
    }
    $stmt->close();

    $confirmedCount = (int) ($paymentCounts['confirmed_count'] ?? 0);
    $pendingCount = (int) ($paymentCounts['pending_count'] ?? 0);
    $summary['due_date'] = $dueDate;
    $summary['has_pending_submission'] = $pendingCount > 0;

    if ($confirmedCount > 0) {
        $summary['status'] = 'Paid';
        $summary['amount_due'] = 0.0;
    } elseif ($pendingCount > 0) {
        $summary['status'] = 'Pending';
    } elseif ($advancedToNextCycle && $dueDate > $today) {
        $summary['status'] = 'Upcoming';
    } elseif (!$parsedMoveInDate) {
        $summary['status'] = 'Pending';
    } elseif ($today->format('Y-m-d') <= $dueDate->format('Y-m-d')) {
        $summary['status'] = 'Pending';
    } else {
        $summary['status'] = 'Overdue';
    }

    return $summary;
}
