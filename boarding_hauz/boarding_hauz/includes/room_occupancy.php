<?php

function calculateRoomOccupancy(int $capacity, int $occupants, string $storedStatus): array
{
    $capacity = max(0, $capacity);
    $occupants = max(0, $occupants);
    $isMaintenance = $storedStatus === 'Maintenance';
    $availableSlots = max(0, $capacity - $occupants);
    $isStoredUnavailable = in_array($storedStatus, ['Occupied', 'Full'], true);

    if ($isMaintenance) {
        $occupancyStatus = 'Maintenance';
    } elseif ($capacity <= 0) {
        $occupancyStatus = 'Unavailable';
    } elseif ($occupants === 0) {
        $occupancyStatus = 'Available';
    } elseif ($occupants < $capacity) {
        $occupancyStatus = 'Partially Occupied';
    } else {
        $occupancyStatus = 'Fully Occupied';
    }

    return [
        'current_occupants' => $occupants,
        'available_slots' => $availableSlots,
        'occupancy_percentage' => $capacity > 0 ? min(100.0, ($occupants / $capacity) * 100) : 0.0,
        'occupancy_status' => $occupancyStatus,
        'accepts_tenants' => !$isMaintenance && !$isStoredUnavailable && $capacity > 0 && $occupants < $capacity,
    ];
}

function synchronizeRoomAvailability(mysqli $conn, int $roomId): void
{
    if ($roomId <= 0) {
        throw new InvalidArgumentException('A valid room is required to synchronize availability.');
    }

    $stmt = $conn->prepare('SELECT capacity, status FROM rooms WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$room) {
        throw new RuntimeException('The room could not be found while updating availability.');
    }
    if ($room['status'] === 'Maintenance') {
        return;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tenant_leases WHERE room_id = ? AND status = 'active'");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $activeLeases = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $newStatus = (int) $room['capacity'] > 0 && $activeLeases >= (int) $room['capacity']
        ? 'Occupied'
        : 'Available';
    if ($newStatus !== $room['status']) {
        $stmt = $conn->prepare('UPDATE rooms SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $newStatus, $roomId);
        $stmt->execute();
        $stmt->close();
    }
}

function attachRoomOccupancy(mysqli $conn, array $rooms): array
{
    if (!$rooms) {
        return [];
    }

    $roomIds = [];
    foreach ($rooms as $room) {
        $roomId = array_key_exists('room_id', $room)
            ? (int) ($room['room_id'] ?? 0)
            : (int) ($room['id'] ?? 0);
        if ($roomId > 0) {
            $roomIds[$roomId] = $roomId;
        }
    }

    $occupantCounts = [];
    if ($roomIds) {
        $roomIds = array_values($roomIds);
        $placeholders = implode(', ', array_fill(0, count($roomIds), '?'));
        $stmt = $conn->prepare("
            SELECT room_id, COUNT(*) AS current_occupants
            FROM tenant_leases
            WHERE status = 'active' AND room_id IN ($placeholders)
            GROUP BY room_id
        ");
        $bindValues = array_merge([str_repeat('i', count($roomIds))], $roomIds);
        $bindParameters = [];
        foreach ($bindValues as $index => &$value) {
            $bindParameters[$index] = &$value;
        }
        call_user_func_array([$stmt, 'bind_param'], $bindParameters);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $occupantCounts[(int) $row['room_id']] = (int) $row['current_occupants'];
        }
        $stmt->close();
    }

    foreach ($rooms as &$room) {
        $capacity = (int) ($room['capacity'] ?? 0);
        $roomId = array_key_exists('room_id', $room)
            ? (int) ($room['room_id'] ?? 0)
            : (int) ($room['id'] ?? 0);
        $occupants = $occupantCounts[$roomId] ?? 0;
        $summary = calculateRoomOccupancy(
            $capacity,
            $occupants,
            (string) (array_key_exists('room_id', $room)
                ? ($room['room_status'] ?? '')
                : ($room['status'] ?? $room['room_status'] ?? ''))
        );
        foreach ($summary as $key => $value) {
            $room[$key] = $value;
        }
    }
    unset($room);

    return $rooms;
}
