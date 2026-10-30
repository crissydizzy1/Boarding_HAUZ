<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!in_array('--confirm-consolidation', $argv, true)) {
    fwrite(STDERR, "Refusing to consolidate landlord accounts without --confirm-consolidation.\n");
    exit(2);
}

$masterPassword = getenv('BOARDING_HAUZ_MASTER_PASSWORD');
if (!is_string($masterPassword) || $masterPassword === '') {
    fwrite(STDERR, "Set BOARDING_HAUZ_MASTER_PASSWORD before running this script.\n");
    exit(2);
}
if (strlen($masterPassword) < 12) {
    fwrite(STDERR, "The master password must be at least 12 characters.\n");
    exit(2);
}

require_once __DIR__ . '/../includes/db_connect.php';

$masterUsername = 'Landlord';
$masterEmail = 'landlord@boardinghauz.local';
$expectedBranches = [
    'Surallah' => 'Surallah, South Cotabato',
    'Banga' => 'Banga, South Cotabato',
    'Koronadal City' => 'Koronadal City, South Cotabato',
    'Norala' => 'Norala, South Cotabato',
    'Tupi' => 'Tupi, South Cotabato',
    'Polomolok' => 'Polomolok, South Cotabato',
    'Santo Niño' => 'Santo Niño, South Cotabato',
    "T'Boli" => "T'Boli, South Cotabato",
    'Lake Sebu' => 'Lake Sebu, South Cotabato',
    'Tantangan' => 'Tantangan, South Cotabato',
    'Tampakan' => 'Tampakan, South Cotabato',
];

$conn->begin_transaction();
try {
    $stmt = $conn->prepare('SELECT id, username, email, role FROM users WHERE username = ? OR email = ?');
    $stmt->bind_param('ss', $masterUsername, $masterEmail);
    $stmt->execute();
    $conflicts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $masterId = 0;
    foreach ($conflicts as $account) {
        if ($account['username'] === $masterUsername && $account['email'] === $masterEmail
            && $account['role'] === 'landlord') {
            if ($masterId !== 0) {
                throw new RuntimeException('More than one account matches the master identity.');
            }
            $masterId = (int) $account['id'];
        } else {
            throw new RuntimeException('The requested username or email is already used by a different account.');
        }
    }

    $passwordHash = password_hash($masterPassword, PASSWORD_DEFAULT);
    if (!is_string($passwordHash)) {
        throw new RuntimeException('The master password could not be hashed.');
    }
    $landlordRole = 'landlord';
    $activeStatus = 'active';
    if ($masterId > 0) {
        $stmt = $conn->prepare('UPDATE users SET password = ?, status = ? WHERE id = ? AND role = ?');
        $stmt->bind_param('ssis', $passwordHash, $activeStatus, $masterId, $landlordRole);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $masterUsername, $masterEmail, $passwordHash, $landlordRole, $activeStatus);
        $stmt->execute();
        $masterId = (int) $conn->insert_id;
        $stmt->close();
    }

    $stmt = $conn->prepare('SELECT id, branch_name, location FROM branches ORDER BY id FOR UPDATE');
    $stmt->execute();
    $existingBranches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $usedBranchIds = [];
    foreach ($expectedBranches as $municipality => $location) {
        $normalizedName = strtolower($municipality);
        $selectedBranch = null;
        foreach ($existingBranches as $branch) {
            $branchId = (int) $branch['id'];
            if (isset($usedBranchIds[$branchId])) {
                continue;
            }
            $branchName = strtolower(trim((string) $branch['branch_name']));
            $branchLocation = strtolower(trim((string) $branch['location']));
            if ($branchName === $normalizedName . ' branch' || $branchName === $normalizedName) {
                $selectedBranch = $branch;
                break;
            }
            if ($branchLocation !== '' && strpos($branchLocation, $normalizedName) !== false) {
                $selectedBranch = $branch;
            }
        }

        $branchName = $municipality . ' Branch';
        if ($selectedBranch) {
            $branchId = (int) $selectedBranch['id'];
            $usedBranchIds[$branchId] = true;
            $stmt = $conn->prepare('UPDATE branches SET landlord_id = ?, branch_name = ?, location = ? WHERE id = ?');
            $stmt->bind_param('issi', $masterId, $branchName, $location, $branchId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare('INSERT INTO branches (landlord_id, branch_name, location) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $masterId, $branchName, $location);
            $stmt->execute();
            $usedBranchIds[(int) $conn->insert_id] = true;
            $stmt->close();
        }
    }

    $stmt = $conn->prepare('UPDATE branches SET landlord_id = ? WHERE landlord_id <> ?');
    $stmt->bind_param('ii', $masterId, $masterId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'landlord' AND id <> ? FOR UPDATE");
    $stmt->bind_param('i', $masterId);
    $stmt->execute();
    $oldLandlordIds = array_map(
        'intval',
        array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id')
    );
    $stmt->close();

    if ($oldLandlordIds) {
        $deletedAccounts = 0;
        foreach ($oldLandlordIds as $oldLandlordId) {
            foreach (['rooms', 'tenants'] as $table) {
                $stmt = $conn->prepare("UPDATE {$table} SET admin_id = ? WHERE admin_id = ?");
                $stmt->bind_param('ii', $masterId, $oldLandlordId);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare('UPDATE room_requests SET reviewed_by = ? WHERE reviewed_by = ?');
            $stmt->bind_param('ii', $masterId, $oldLandlordId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('UPDATE users SET created_by = ? WHERE created_by = ?');
            $stmt->bind_param('ii', $masterId, $oldLandlordId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'landlord'");
            $stmt->bind_param('i', $oldLandlordId);
            $stmt->execute();
            $deletedAccounts += $stmt->affected_rows;
            $stmt->close();
        }
    } else {
        $deletedAccounts = 0;
    }

    $conn->commit();
    echo 'Master landlord ID: ' . $masterId . PHP_EOL;
    echo 'South Cotabato branches ensured: ' . count($expectedBranches) . PHP_EOL;
    echo 'Existing landlord accounts consolidated: ' . $deletedAccounts . PHP_EOL;
} catch (Throwable $exception) {
    $conn->rollback();
    fwrite(STDERR, 'Landlord consolidation failed; changes were rolled back: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
