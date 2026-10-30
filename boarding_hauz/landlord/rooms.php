<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/room_images.php';
require_once __DIR__ . '/../includes/room_display.php';
require_once __DIR__ . '/../includes/room_occupancy.php';
requireRole('landlord');

$admin_title = 'Room Management';
$branchId = requireSelectedBranch();
$adminId = currentBranchLandlordId();
$statusOptions = ['Available', 'Maintenance'];
$roomFilterOptions = ['Available', 'Partially Occupied', 'Fully Occupied', 'Maintenance'];
$roomImageDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'rooms';
$roomImageLibraryDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'rooms';
$roomImageLibraryFiles = array_map(
    'basename',
    glob($roomImageLibraryDirectory . DIRECTORY_SEPARATOR . 'room-*.jpg') ?: []
);
sort($roomImageLibraryFiles, SORT_NATURAL);
$successMessages = [
    'created' => 'Room has been added successfully.',
    'updated' => 'Room has been updated successfully.',
    'assigned' => 'Tenant has been assigned to the room successfully.',
    'deleted' => 'Room has been deleted successfully.',
    'request_approved' => 'Room application approved. The tenant and room assignment were updated.',
    'request_rejected' => 'Room application rejected.',
];
$successKey = $_GET['success'] ?? '';
$success = is_string($successKey) ? ($successMessages[$successKey] ?? '') : '';
if ($successKey === 'deleted' && ($_GET['cleanup'] ?? '') === 'failed') {
    $success = 'Room deleted. Its image file could not be removed; check the server log.';
} elseif (($successKey === 'created' || $successKey === 'updated') && ($_GET['image_cleanup'] ?? '') === 'failed') {
    $success = 'Room details were saved, but a previous image file could not be removed; check the server log.';
}
$error = '';
$roomRequestError = false;
$requestReviewId = is_scalar($_GET['view_request'] ?? null) ? (int) $_GET['view_request'] : 0;

if (empty($_SESSION['admin_rooms_csrf'])) {
    $_SESSION['admin_rooms_csrf'] = bin2hex(random_bytes(32));
}

$formValues = [
    'room_number' => '',
    'room_type' => '',
    'capacity' => '',
    'monthly_rate' => '',
    'status' => 'Available',
];
$requestedEditId = $_GET['edit'] ?? null;
$editId = is_scalar($requestedEditId) ? (int) $requestedEditId : 0;
$requestedAssignRoomId = $_GET['assign_room'] ?? null;
$assignRoomId = is_scalar($requestedAssignRoomId) ? (int) $requestedAssignRoomId : 0;
$addMode = isset($_GET['add']) && !empty($_GET['add']);

function getRoomImageFiles(int $roomId, string $directory): array
{
    if ($roomId <= 0 || !is_dir($directory)) {
        return [];
    }

    $files = glob($directory . DIRECTORY_SEPARATOR . 'room-' . $roomId . '-*') ?: [];
    return array_values(array_filter($files, 'is_file'));
}

function getRoomImageFile(int $roomId, string $directory): ?string
{
    $files = getRoomImageFiles($roomId, $directory);
    if (!$files) {
        return null;
    }

    usort($files, static function (string $first, string $second): int {
        return strcmp(basename($second), basename($first));
    });

    return $files[0];
}

function validateRoomImageUpload(): array
{
    if (!isset($_FILES['room_image']) || !is_array($_FILES['room_image'])) {
        return ['upload' => null, 'error' => ''];
    }

    $file = $_FILES['room_image'];
    $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!is_int($uploadError) && (!is_string($uploadError) || !ctype_digit($uploadError))) {
        return ['upload' => null, 'error' => 'The image upload could not be verified.'];
    }
    $uploadError = (int) $uploadError;
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return ['upload' => null, 'error' => ''];
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        return ['upload' => null, 'error' => 'The image upload failed. Please choose an image smaller than 5 MB.'];
    }

    $temporaryPath = $file['tmp_name'] ?? '';
    $originalName = $file['name'] ?? '';
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath) || !is_string($originalName)) {
        return ['upload' => null, 'error' => 'The uploaded image is invalid.'];
    }

    $fileSize = filesize($temporaryPath);
    if ($fileSize === false || $fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
        return ['upload' => null, 'error' => 'Room images must be no larger than 5 MB.'];
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $extensionMimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
    if (!isset($extensionMimeTypes[$extension])) {
        return ['upload' => null, 'error' => 'Use a JPG, PNG, or WebP image.'];
    }

    $imageInfo = @getimagesize($temporaryPath);
    if (!$imageInfo || ($imageInfo['mime'] ?? '') !== $extensionMimeTypes[$extension]) {
        return ['upload' => null, 'error' => 'The file contents do not match a supported image type.'];
    }

    return [
        'upload' => ['temporary_path' => $temporaryPath, 'extension' => $extension],
        'error' => '',
    ];
}

function saveRoomImageUpload(array $upload, int $roomId, string $directory): string
{
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The room image folder could not be created.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('The room image folder is not writable.');
    }

    $fileName = 'room-' . $roomId . '-z-' . sprintf('%.6F', microtime(true)) . '-' . bin2hex(random_bytes(12)) . '.' . $upload['extension'];
    $destination = $directory . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($upload['temporary_path'], $destination)) {
        throw new RuntimeException('The image could not be saved. Please try again.');
    }

    return $destination;
}

function deleteRoomImageFiles(int $roomId, string $directory): bool
{
    $success = true;
    foreach (getRoomImageFiles($roomId, $directory) as $file) {
        if (!unlink($file)) {
            error_log('Could not remove room image: ' . $file);
            $success = false;
        }
    }
    return $success;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($postedAction === 'create') {
        $addMode = true;
    }
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $imageUpload = null;
    $imageUploadError = '';
    $selectedRoomImage = is_string($_POST['room_library_image'] ?? null)
        ? trim($_POST['room_library_image'])
        : '';
    $formValues['room_number'] = is_string($_POST['room_number'] ?? null) ? trim($_POST['room_number']) : '';
    $formValues['room_type'] = is_string($_POST['room_type'] ?? null) ? trim($_POST['room_type']) : '';
    $formValues['capacity'] = is_string($_POST['capacity'] ?? null) ? trim($_POST['capacity']) : '';
    $formValues['monthly_rate'] = is_string($_POST['monthly_rate'] ?? null) ? trim($_POST['monthly_rate']) : '';
    $formValues['status'] = is_string($_POST['status'] ?? null) ? trim($_POST['status']) : '';

    if ($postedAction === 'create' || $postedAction === 'update') {
        $imageResult = validateRoomImageUpload();
        $imageUpload = $imageResult['upload'];
        $imageUploadError = $imageResult['error'];
        if ($selectedRoomImage !== '' && !in_array($selectedRoomImage, $roomImageLibraryFiles, true)) {
            $imageUploadError = 'Choose a room photo from the local library.';
        } elseif ($selectedRoomImage !== '' && $imageUpload !== null) {
            $imageUploadError = 'Choose either a library photo or an upload, not both.';
        }
    }

    if (!hash_equals($_SESSION['admin_rooms_csrf'], $postedToken)) {
        $error = 'Your session could not be verified. Refresh the page and try again.';
    } elseif ($postedAction === 'approve_room_request' || $postedAction === 'reject_room_request') {
        $requestIdValue = is_scalar($_POST['request_id'] ?? null) ? (string) $_POST['request_id'] : '';
        $requestReviewId = ctype_digit($requestIdValue) ? (int) $requestIdValue : 0;

        if ($requestReviewId <= 0) {
            $error = 'Select a valid room application.';
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('
                    SELECT rr.tenant_id, rr.room_id, rr.status
                    FROM room_requests rr
                    INNER JOIN rooms r ON r.id = rr.room_id
                    WHERE rr.id = ? AND r.branch_id = ?
                    LIMIT 1 FOR UPDATE
                ');
                $stmt->bind_param('ii', $requestReviewId, $branchId);
                $stmt->execute();
                $roomRequest = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$roomRequest) {
                    throw new RuntimeException('The selected room application could not be found.');
                }
                if ($roomRequest['status'] !== 'Pending') {
                    throw new RuntimeException('Only pending room applications can be reviewed.');
                }

                $tenantId = (int) $roomRequest['tenant_id'];
                $roomId = (int) $roomRequest['room_id'];
                $reviewedBy = (int) $_SESSION['user_id'];

                if ($postedAction === 'reject_room_request') {
                    $stmt = $conn->prepare('SELECT room_id, branch_id FROM tenants WHERE id = ? AND (branch_id IS NULL OR branch_id = ?) LIMIT 1 FOR UPDATE');
                    $stmt->bind_param('ii', $tenantId, $branchId);
                    $stmt->execute();
                    $applicant = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$applicant) {
                        throw new RuntimeException('The applicant record no longer exists.');
                    }
                    if ($applicant['room_id'] !== null) {
                        throw new RuntimeException('An applicant with an assigned room cannot be rejected. Review the room assignment first.');
                    }

                    $stmt = $conn->prepare("UPDATE tenants SET status = 'rejected' WHERE id = ? AND room_id IS NULL LIMIT 1");
                    $stmt->bind_param('i', $tenantId);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("
                        UPDATE room_requests
                        SET status = 'Rejected', reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?
                        WHERE id = ? AND status = 'Pending'
                        LIMIT 1
                    ");
                    $stmt->bind_param('ii', $reviewedBy, $requestReviewId);
                    $stmt->execute();
                    $updatedRequest = $stmt->affected_rows === 1;
                    $stmt->close();
                    if (!$updatedRequest) {
                        throw new RuntimeException('The application status changed before the rejection could be saved.');
                    }

                    $conn->commit();
                    header('Location: /boarding_hauz/landlord/rooms.php?success=request_rejected#room-applications');
                    exit;
                }

                $stmt = $conn->prepare('SELECT status, capacity, admin_id, branch_id FROM rooms WHERE id = ? AND branch_id = ? LIMIT 1 FOR UPDATE');
                $stmt->bind_param('ii', $roomId, $branchId);
                $stmt->execute();
                $requestedRoom = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$requestedRoom) {
                    throw new RuntimeException('The requested room no longer exists.');
                }
                if ($requestedRoom['status'] === 'Maintenance') {
                    throw new RuntimeException('The requested room is no longer available.');
                }

                $stmt = $conn->prepare('SELECT status, room_id, branch_id FROM tenants WHERE id = ? AND (branch_id IS NULL OR branch_id = ?) LIMIT 1 FOR UPDATE');
                $stmt->bind_param('ii', $tenantId, $branchId);
                $stmt->execute();
                $applicant = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$applicant) {
                    throw new RuntimeException('The applicant record no longer exists.');
                }
                if ($applicant['room_id'] !== null) {
                    throw new RuntimeException('The applicant already has an assigned room.');
                }
                if (in_array($applicant['status'], ['rejected', 'inactive'], true)) {
                    throw new RuntimeException('This applicant is not eligible for room assignment.');
                }

                $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tenants WHERE room_id = ? AND status = 'active'");
                $stmt->bind_param('i', $roomId);
                $stmt->execute();
                $currentOccupants = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
                $stmt->close();
                if ($currentOccupants >= (int) $requestedRoom['capacity']) {
                    throw new RuntimeException('This room is already at full capacity. No available slots remain.');
                }

                $roomAdminId = (int) $requestedRoom['admin_id'];
                $roomBranchId = (int) $requestedRoom['branch_id'];
                $stmt = $conn->prepare("UPDATE tenants SET room_id = ?, admin_id = ?, branch_id = ?, status = 'active' WHERE id = ? AND room_id IS NULL AND (branch_id IS NULL OR branch_id = ?) LIMIT 1");
                $stmt->bind_param('iiiii', $roomId, $roomAdminId, $roomBranchId, $tenantId, $branchId);
                $stmt->execute();
                $tenantAssigned = $stmt->affected_rows === 1;
                $stmt->close();
                if (!$tenantAssigned) {
                    throw new RuntimeException('The applicant could not be assigned to the requested room.');
                }

                $stmt = $conn->prepare("
                    UPDATE room_requests
                    SET status = 'Approved', reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?
                    WHERE id = ? AND status = 'Pending'
                    LIMIT 1
                ");
                $stmt->bind_param('ii', $reviewedBy, $requestReviewId);
                $stmt->execute();
                $requestApproved = $stmt->affected_rows === 1;
                $stmt->close();
                if (!$requestApproved) {
                    throw new RuntimeException('The application status changed before approval could be saved.');
                }

                $conn->commit();
                header('Location: /boarding_hauz/landlord/rooms.php?success=request_approved#room-applications');
                exit;
            } catch (RuntimeException $exception) {
                $conn->rollback();
                $error = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                error_log('Room application review failed: ' . $exception->getMessage());
                $error = 'The room application could not be reviewed due to a database error.';
            }
        }
    } elseif ($postedAction === 'create' || $postedAction === 'update') {
        $roomId = $postedAction === 'update' && is_scalar($_POST['room_id'] ?? null)
            ? (int) $_POST['room_id']
            : 0;
        $editId = $roomId;
        $addMode = $postedAction === 'create';

        if ($imageUploadError !== '') {
            $error = $imageUploadError;
        } elseif ($postedAction === 'update' && $roomId <= 0) {
            $error = 'Invalid room selected.';
        } elseif ($formValues['room_number'] === '' || strlen($formValues['room_number']) > 20) {
            $error = 'Room number is required and must be 20 characters or fewer.';
        } elseif ($formValues['room_type'] === '' || strlen($formValues['room_type']) > 50) {
            $error = 'Room type is required and must be 50 characters or fewer.';
        } elseif (filter_var($formValues['capacity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false) {
            $error = 'Capacity must be a positive whole number.';
        } elseif (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $formValues['monthly_rate']) || (float) $formValues['monthly_rate'] > 99999999.99) {
            $error = 'Monthly rate must be a valid, non-negative amount with no more than two decimal places.';
        } elseif (!in_array($formValues['status'], $statusOptions, true)) {
            $error = 'Choose a valid room status.';
        } else {
            $roomExists = true;
            if ($postedAction === 'update') {
                $stmt = $conn->prepare('SELECT id FROM rooms WHERE id = ? AND branch_id = ? LIMIT 1');
                $stmt->bind_param('ii', $roomId, $branchId);
                $stmt->execute();
                $roomExists = (bool) $stmt->get_result()->fetch_assoc();
                $stmt->close();

            }

            if (!$roomExists) {
                $error = 'The selected room could not be found.';
            } else {
                $duplicateSql = 'SELECT id FROM rooms WHERE room_number = ? AND branch_id = ?';
                if ($postedAction === 'update') {
                    $duplicateSql .= ' AND id <> ?';
                }
                $duplicateSql .= ' LIMIT 1';
                $stmt = $conn->prepare($duplicateSql);
                if ($postedAction === 'update') {
                    $stmt->bind_param('sii', $formValues['room_number'], $branchId, $roomId);
                } else {
                    $stmt->bind_param('si', $formValues['room_number'], $branchId);
                }
                $stmt->execute();
                $duplicate = (bool) $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($duplicate) {
                    $error = 'Room number already exists.';
                } else {
                    $monthlyRate = (float) $formValues['monthly_rate'];
                    $newImagePath = null;
                    try {
                        $conn->begin_transaction();
                        $capacity = (int) $formValues['capacity'];
                        $roomStatus = $formValues['status'] === 'Maintenance' ? 'Maintenance' : 'Available';
                        if ($postedAction === 'create') {
                            $stmt = $conn->prepare('INSERT INTO rooms (branch_id, admin_id, room_number, room_type, capacity, monthly_rate, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                            $stmt->bind_param('iissids', $branchId, $adminId, $formValues['room_number'], $formValues['room_type'], $capacity, $monthlyRate, $roomStatus);
                            $stmt->execute();
                            $roomId = (int) $conn->insert_id;
                            $stmt->close();
                        } else {
                            $stmt = $conn->prepare('SELECT id FROM rooms WHERE id = ? AND branch_id = ? LIMIT 1 FOR UPDATE');
                            $stmt->bind_param('ii', $roomId, $branchId);
                            $stmt->execute();
                            $lockedRoom = (bool) $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            if (!$lockedRoom) {
                                throw new RuntimeException('The selected room could not be found.');
                            }

                            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tenants WHERE room_id = ? AND status = 'active'");
                            $stmt->bind_param('i', $roomId);
                            $stmt->execute();
                            $currentOccupants = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
                            $stmt->close();
                            if ($capacity < $currentOccupants) {
                                throw new RuntimeException('Room capacity cannot be lower than its current active occupants.');
                            }

                            $stmt = $conn->prepare('UPDATE rooms SET room_number = ?, room_type = ?, capacity = ?, monthly_rate = ?, status = ? WHERE id = ? AND branch_id = ? LIMIT 1');
                            $stmt->bind_param('ssidsii', $formValues['room_number'], $formValues['room_type'], $capacity, $monthlyRate, $roomStatus, $roomId, $branchId);
                            $stmt->execute();
                            $stmt->close();
                        }
                        if ($imageUpload !== null) {
                            $newImagePath = saveRoomImageUpload($imageUpload, $roomId, $roomImageDirectory);
                        }
                        if ($imageUpload !== null || $selectedRoomImage !== '') {
                            $relativeImagePath = $imageUpload !== null
                                ? 'uploads/rooms/' . basename($newImagePath)
                                : 'assets/images/rooms/' . $selectedRoomImage;
                            $stmt = $conn->prepare('UPDATE rooms SET image_path = ? WHERE id = ? AND branch_id = ? LIMIT 1');
                            $stmt->bind_param('sii', $relativeImagePath, $roomId, $branchId);
                            $stmt->execute();
                            $stmt->close();
                        }
                        $conn->commit();
                        $imageCleanupSucceeded = true;
                        if ($imageUpload !== null || $selectedRoomImage !== '') {
                            foreach (getRoomImageFiles($roomId, $roomImageDirectory) as $oldImagePath) {
                                if ($oldImagePath !== $newImagePath && !unlink($oldImagePath)) {
                                    error_log('Could not remove replaced room image: ' . $oldImagePath);
                                    $imageCleanupSucceeded = false;
                                }
                            }
                        }
                        $successQuery = $postedAction === 'create' ? 'created' : 'updated';
                        header('Location: /boarding_hauz/landlord/rooms.php?success=' . $successQuery . ($imageCleanupSucceeded ? '' : '&image_cleanup=failed'));
                        exit;
                    } catch (RuntimeException $exception) {
                        $conn->rollback();
                        if ($newImagePath !== null && is_file($newImagePath)) {
                            unlink($newImagePath);
                        }
                        $error = $exception->getMessage();
                    } catch (mysqli_sql_exception $exception) {
                        $conn->rollback();
                        if ($newImagePath !== null && is_file($newImagePath)) {
                            unlink($newImagePath);
                        }
                        if ((int) $exception->getCode() === 1062) {
                            $error = 'Room number already exists. Choose a different number.';
                        } else {
                            error_log('Room save failed: ' . $exception->getMessage());
                            $error = 'The room could not be saved. Please try again.';
                        }
                    }
                }
            }
        }
    } elseif ($postedAction === 'assign_tenant' || $postedAction === 'delete') {
        $roomId = is_scalar($_POST['room_id'] ?? null) ? (int) $_POST['room_id'] : 0;
        $tenantId = is_scalar($_POST['tenant_id'] ?? null) ? (int) $_POST['tenant_id'] : 0;
        $assignRoomId = $roomId;

        if ($roomId <= 0 || ($postedAction !== 'delete' && $tenantId <= 0)) {
            $error = 'Please select a valid room and tenant.';
        } elseif ($postedAction === 'delete') {
            $roomId = is_scalar($_POST['room_id'] ?? null) ? (int) $_POST['room_id'] : 0;

            if ($roomId <= 0) {
                $error = 'Invalid room selected for deletion.';
            } else {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('SELECT id FROM rooms WHERE id = ? AND branch_id = ? LIMIT 1 FOR UPDATE');
                    $stmt->bind_param('ii', $roomId, $branchId);
                    $stmt->execute();
                    $roomExists = (bool) $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$roomExists) {
                        throw new RuntimeException('The selected room could not be found.');
                    }

                    $stmt = $conn->prepare('SELECT id FROM tenants WHERE room_id = ? LIMIT 1');
                    $stmt->bind_param('i', $roomId);
                    $stmt->execute();
                    $hasTenant = (bool) $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($hasTenant) {
                        throw new RuntimeException('This room cannot be deleted while a tenant is assigned. Handle the tenant assignment first.');
                    }

                    $stmt = $conn->prepare('SELECT id FROM maintenance_requests WHERE room_id = ? LIMIT 1');
                    $stmt->bind_param('i', $roomId);
                    $stmt->execute();
                    $hasMaintenanceHistory = (bool) $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($hasMaintenanceHistory) {
                        throw new RuntimeException('This room cannot be deleted because it has maintenance history.');
                    }

                    $stmt = $conn->prepare('SELECT id FROM room_requests WHERE room_id = ? LIMIT 1');
                    $stmt->bind_param('i', $roomId);
                    $stmt->execute();
                    $hasRoomRequestHistory = (bool) $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($hasRoomRequestHistory) {
                        throw new RuntimeException('This room cannot be deleted because it has room application history.');
                    }

                    $stmt = $conn->prepare('DELETE FROM rooms WHERE id = ? AND branch_id = ? LIMIT 1');
                    $stmt->bind_param('ii', $roomId, $branchId);
                    $stmt->execute();
                    $deleted = $stmt->affected_rows === 1;
                    $stmt->close();
                    if (!$deleted) {
                        throw new RuntimeException('The room could not be deleted. Refresh and try again.');
                    }
                    $conn->commit();
                    $cleanupSucceeded = deleteRoomImageFiles($roomId, $roomImageDirectory);
                    header('Location: /boarding_hauz/landlord/rooms.php?success=deleted' . ($cleanupSucceeded ? '' : '&cleanup=failed'));
                    exit;
                } catch (RuntimeException $exception) {
                    $conn->rollback();
                    $error = $exception->getMessage();
                } catch (mysqli_sql_exception $exception) {
                    $conn->rollback();
                    error_log('Room deletion failed: ' . $exception->getMessage());
                    $error = 'The room could not be deleted. Please try again.';
                }
            }
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('SELECT id, status, capacity, admin_id, branch_id FROM rooms WHERE id = ? AND branch_id = ? LIMIT 1 FOR UPDATE');
                $stmt->bind_param('ii', $roomId, $branchId);
                $stmt->execute();
                $roomRecord = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$roomRecord) {
                    throw new RuntimeException('The selected room could not be found.');
                }
                if ($roomRecord['status'] === 'Maintenance') {
                    throw new RuntimeException('A room under maintenance cannot receive a tenant.');
                }

                $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tenants WHERE room_id = ? AND status = 'active'");
                $stmt->bind_param('i', $roomId);
                $stmt->execute();
                $currentOccupants = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
                $stmt->close();
                if ($currentOccupants >= (int) $roomRecord['capacity']) {
                    throw new RuntimeException('This room is already at full capacity. No available slots remain.');
                }

                $stmt = $conn->prepare('SELECT id, room_id, status, branch_id FROM tenants WHERE id = ? AND branch_id = ? LIMIT 1 FOR UPDATE');
                $stmt->bind_param('ii', $tenantId, $branchId);
                $stmt->execute();
                $tenantRecord = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$tenantRecord || $tenantRecord['status'] !== 'active' || $tenantRecord['room_id'] !== null) {
                    throw new RuntimeException('Only an active, unassigned tenant can be assigned to a room.');
                }
                $roomAdminId = (int) $roomRecord['admin_id'];
                $roomBranchId = (int) $roomRecord['branch_id'];
                $stmt = $conn->prepare('UPDATE tenants SET room_id = ?, admin_id = ?, branch_id = ? WHERE id = ? AND room_id IS NULL AND branch_id = ? LIMIT 1');
                $stmt->bind_param('iiiii', $roomId, $roomAdminId, $roomBranchId, $tenantId, $branchId);
                $stmt->execute();
                $updatedTenant = $stmt->affected_rows === 1;
                $stmt->close();
                if (!$updatedTenant) {
                    throw new RuntimeException('The tenant could not be assigned. Refresh and try again.');
                }

                $conn->commit();
                header('Location: /boarding_hauz/landlord/rooms.php?success=assigned');
                exit;
            } catch (RuntimeException $exception) {
                $conn->rollback();
                $error = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                error_log('Tenant room assignment failed: ' . $exception->getMessage());
                $error = 'The tenant could not be assigned due to a database error. Please try again.';
            }
        }
    } else {
        $error = 'Invalid room action.';
    }
}

$editRoom = null;
$editRoomImageUrl = null;
if ($editId > 0) {
    $stmt = $conn->prepare('SELECT id, room_number, room_type, capacity, monthly_rate, status, image_path FROM rooms WHERE id = ? AND branch_id = ? LIMIT 1');
    $stmt->bind_param('ii', $editId, $branchId);
    $stmt->execute();
    $editRoom = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($editRoom) {
        $editRoomImageUrl = getRoomImageUrlForDisplay($editId, $editRoom['image_path']);
    }
    if ($editRoom && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $formValues = [
            'room_number' => $editRoom['room_number'],
            'room_type' => $editRoom['room_type'],
            'capacity' => (string) $editRoom['capacity'],
            'monthly_rate' => (string) $editRoom['monthly_rate'],
            'status' => $editRoom['status'] === 'Maintenance' ? 'Maintenance' : 'Available',
        ];
    }
}

$roomCounts = ['total' => 0, 'available' => 0, 'occupied' => 0, 'maintenance' => 0];
$stmt = $conn->prepare('SELECT id, capacity, status FROM rooms WHERE branch_id = ?');
$stmt->bind_param('i', $branchId);
$stmt->execute();
$countedRooms = attachRoomOccupancy($conn, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
$stmt->close();
foreach ($countedRooms as $countedRoom) {
    $roomCounts['total']++;
    if ($countedRoom['accepts_tenants']) {
        $roomCounts['available']++;
    }
    if ($countedRoom['occupancy_status'] === 'Fully Occupied') {
        $roomCounts['occupied']++;
    }
    if ($countedRoom['occupancy_status'] === 'Maintenance') {
        $roomCounts['maintenance']++;
    }
}

$roomRequests = [];
$pendingRoomRequestCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM room_requests rr INNER JOIN rooms r ON r.id = rr.room_id WHERE rr.status = 'Pending' AND r.branch_id = ?");
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $pendingRoomRequestCount = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT
            rr.id,
            rr.tenant_id,
            rr.room_id,
            rr.status,
            rr.created_at,
            rr.reviewed_at,
            rr.reviewed_by,
            t.full_name AS applicant_name,
            t.contact_number,
            t.status AS tenant_status,
            u.email,
            r.room_number,
            r.room_type,
            r.capacity,
            r.monthly_rate,
            r.status AS room_status,
            b.branch_name
        FROM room_requests rr
        INNER JOIN tenants t ON t.id = rr.tenant_id
        LEFT JOIN users u ON u.id = t.user_id
        INNER JOIN rooms r ON r.id = rr.room_id
        LEFT JOIN branches b ON b.id = r.branch_id
        WHERE r.branch_id = ? AND (t.branch_id IS NULL OR t.branch_id = r.branch_id)
        ORDER BY CASE WHEN rr.status = 'Pending' THEN 0 ELSE 1 END,
                 rr.created_at DESC,
                 rr.id DESC
        LIMIT 100
    ");
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $roomRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $roomRequests = attachRoomOccupancy($conn, $roomRequests);
    foreach ($roomRequests as &$roomRequest) {
        $roomRequest['room_status'] = $roomRequest['occupancy_status'];
    }
    unset($roomRequest);
} catch (mysqli_sql_exception $exception) {
    error_log('Admin room application list failed: ' . $exception->getMessage());
    $roomRequestError = true;
}

$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$search = substr($search, 0, 50);
$filterStatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($filterStatus, $roomFilterOptions, true)) {
    $filterStatus = '';
}

$roomSql = "
    SELECT r.id, r.room_number, r.room_type, r.capacity, r.monthly_rate, r.status, r.image_path,
        b.branch_name,
        (SELECT GROUP_CONCAT(ta.full_name ORDER BY ta.full_name SEPARATOR ', ')
            FROM tenants ta WHERE ta.room_id = r.id AND ta.status = 'active') AS active_tenant_names,
        (SELECT COUNT(*) FROM room_requests rq WHERE rq.room_id = r.id AND rq.status = 'Pending') AS pending_request_count,
        EXISTS(SELECT 1 FROM tenants ta WHERE ta.room_id = r.id) AS has_tenant,
        EXISTS(SELECT 1 FROM maintenance_requests mr WHERE mr.room_id = r.id AND mr.branch_id = r.branch_id) AS has_maintenance_history
    FROM rooms r
    LEFT JOIN branches b ON b.id = r.branch_id
";
$conditions = ['r.branch_id = ?'];
if ($search !== '') {
    $conditions[] = '(r.room_number LIKE ? OR r.room_type LIKE ?)';
}
if ($conditions) {
    $roomSql .= ' WHERE ' . implode(' AND ', $conditions);
}
$roomSql .= ' ORDER BY r.room_number';
$stmt = $conn->prepare($roomSql);
if ($search !== '') {
    $searchPattern = '%' . $search . '%';
    $stmt->bind_param('iss', $branchId, $searchPattern, $searchPattern);
} else {
    $stmt->bind_param('i', $branchId);
}
$stmt->execute();
$result = $stmt->get_result();
$rooms = attachRoomOccupancy($conn, $result->fetch_all(MYSQLI_ASSOC));
if ($filterStatus !== '') {
    $rooms = array_values(array_filter($rooms, static function (array $room) use ($filterStatus): bool {
        if ($filterStatus === 'Available') {
            return $room['accepts_tenants'];
        }
        return $room['occupancy_status'] === $filterStatus;
    }));
}
foreach ($rooms as &$row) {
    $row['image_url'] = getRoomImageUrlForDisplay((int) $row['id'], $row['image_path']);
}
unset($row);
$stmt->close();

$assignRoom = null;
$eligibleTenants = [];
if ($assignRoomId > 0) {
    $stmt = $conn->prepare('SELECT id, room_number, capacity, status FROM rooms WHERE id = ? AND branch_id = ? LIMIT 1');
    $stmt->bind_param('ii', $assignRoomId, $branchId);
    $stmt->execute();
    $assignRoom = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($assignRoom) {
        $assignRoom = attachRoomOccupancy($conn, [$assignRoom])[0];
        if ($assignRoom['accepts_tenants']) {
            $stmt = $conn->prepare("SELECT id, full_name, contact_number FROM tenants WHERE status = 'active' AND room_id IS NULL AND branch_id = ? ORDER BY full_name");
            $stmt->bind_param('i', $branchId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $eligibleTenants[] = $row;
            }
            $stmt->close();
        }
    }
}

include __DIR__ . '/../admin/admin_header.php';
?>
<style>
    .rooms-page{max-width:1200px;margin:0 auto;padding:32px 24px 56px}
    .rooms-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;margin-bottom:22px;padding:24px 28px;border:1px solid #236b67;border-radius:16px;background:#0d5b50}
    .rooms-heading h1{margin:0 0 5px;color:#fff;font:700 31px 'Playfair Display',serif}
    .rooms-heading p{margin:0;color:#dff3f7;font-size:13px}
    .rooms-overview{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:24px}
    .rooms-stat{display:block;padding:15px 17px;border:1px solid #d7e6ea;border-radius:9px;background:#fff;color:inherit;text-decoration:none}
    .rooms-stat:hover,.rooms-stat:focus-visible,.rooms-stat.selected{border-color:#22b486;box-shadow:0 0 0 2px rgba(34,180,134,.12)}
    .rooms-stat span{display:block;color:#536466;font-size:12px;font-weight:700}
    .rooms-stat strong{display:block;margin-top:7px;color:#0d1c1e;font-size:23px}
    .rooms-section-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:24px 0 12px}
    .rooms-section-heading h2,.rooms-panel h2{margin:0;color:#0d1c1e;font:700 22px 'Playfair Display',serif}
    .rooms-panel{margin-bottom:18px;padding:20px;border:1px solid #d7e6ea;border-radius:9px;background:#fff}
    .rooms-panel-intro{margin:5px 0 16px;color:#667b7e;font-size:13px;line-height:1.55}
    .rooms-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
    .rooms-search{display:flex;flex:1 1 250px;gap:8px}
    .rooms-search input,.room-form input,.room-form select,.room-form textarea{min-height:40px;width:100%;padding:9px 11px;border:1px solid #d7e6ea;border-radius:6px;background:#fff;color:#0d1c1e;font:inherit;font-size:13px}
    .room-form textarea{box-sizing:border-box;resize:vertical}
    .rooms-search input:focus,.room-form input:focus,.room-form select:focus,.room-form textarea:focus{outline:2px solid #22b486;outline-offset:1px}
    .rooms-filters{display:flex;flex-wrap:wrap;gap:6px}
    .rooms-filter,.room-button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:7px 11px;border:1px solid #d7e6ea;border-radius:6px;background:#fff;color:#236b67;text-decoration:none;font:inherit;font-size:12px;font-weight:700;cursor:pointer}
    .rooms-filter.selected,.rooms-filter:hover,.room-button:hover{border-color:#22b486;background:#edf9fc}
    .room-button-primary{border-color:#22b486;background:#22b486;color:#fff}
    .room-button-primary:hover{background:#1aa27a;color:#fff}
    .room-button-danger{border-color:#e7c4c4;color:#8a1c1c}
    .room-button-danger:hover{border-color:#d79b9b;background:#fff0f0}
    .room-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
    .room-form label{display:block;margin-bottom:5px;color:#536466;font-size:12px;font-weight:700}
    .room-form input[type=file]{padding:7px}
    .room-form-field-full{grid-column:1/-1}
    .room-image-preview{display:block;width:min(100%,420px);max-height:240px;margin-top:10px;border:1px solid #d7e6ea;border-radius:7px;object-fit:cover}
    .room-image-preview[hidden],.room-modal-preview[hidden]{display:none}
    .room-form-help{margin:6px 0 0;color:#667b7e;font-size:11px;line-height:1.5}
    .room-form-actions{display:flex;flex-wrap:wrap;gap:8px;grid-column:1/-1;margin-top:3px}
    .rooms-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .room-card{display:flex;flex-direction:column;min-width:0;border:1px solid #d7e6ea;border-radius:9px;background:#fff;overflow:hidden}
    .room-card-image{display:block;width:100%;height:170px;background:linear-gradient(135deg,#dff3f7,#edf9fc);object-fit:cover}
    .room-card-image-placeholder{display:grid;place-items:center;color:#236b67;font:600 16px 'Playfair Display',serif}
    .room-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:16px 16px 12px;background:#f7fcfd;border-bottom:1px solid #d7e6ea}
    .room-card-top h3{margin:0;color:#0d1c1e;font:700 20px 'Playfair Display',serif}
    .room-card-type{margin:4px 0 0;color:#667b7e;font-size:12px}
    .room-status{display:inline-block;padding:5px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
    .room-status-available{background:#e3f6ee;color:#18734f}
    .room-status-occupied{background:#dff3f7;color:#236b67}
    .room-status-maintenance{background:#fff4d6;color:#866000}
    .room-status-partially-occupied{background:#e5f0ff;color:#285a9f}
    .room-status-fully-occupied{background:#fce8e8;color:#9b2c2c}
    .room-status-unavailable{background:#f1f2f3;color:#536466}
    .room-card-content{display:grid;gap:11px;padding:15px 16px;flex:1}
    .room-occupancy-progress{width:100%;height:8px;accent-color:#22b486}
    .room-rate{color:#0d1c1e;font-size:19px;font-weight:700}
    .room-rate small{color:#667b7e;font-size:12px;font-weight:500}
    .room-assignee{padding-top:10px;border-top:1px solid #edf2f3;color:#536466;font-size:12px;line-height:1.5}
    .room-assignee strong{color:#0d1c1e}
    .room-request-indicator{color:#236b67;font-size:12px;font-weight:700;text-decoration:none}
    .room-request-indicator:hover{text-decoration:underline}
    .room-card-actions{display:flex;flex-wrap:wrap;gap:7px;padding:0 16px 15px}
    .room-request-list{display:grid;gap:10px;margin-top:16px}
    .room-request-card{padding:15px;border:1px solid #d7e6ea;border-radius:7px;background:#fff}
    .room-request-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
    .room-request-top strong{display:block;color:#0d1c1e;font-size:14px}
    .room-request-top span,.room-request-meta{color:#667b7e;font-size:12px}
    .room-request-meta{display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:8px}
    .room-request-status{display:inline-block;padding:5px 9px;border-radius:20px;background:#fff4d6;color:#866000;font-size:11px;font-weight:700;white-space:nowrap}
    .room-request-status.approved{background:#e3f6ee;color:#18734f}
    .room-request-status.rejected{background:#fff0f0;color:#8a1c1c}
    .room-request-details{margin-top:9px}
    .room-request-details summary{display:inline-block;color:#236b67;font-size:12px;font-weight:700;cursor:pointer}
    .room-request-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px;margin-top:10px;padding:12px;border-radius:6px;background:#f7fcfd;color:#536466;font-size:12px}
    .room-request-detail-grid strong{display:block;margin-bottom:2px;color:#0d1c1e}
    .room-request-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}
    .room-request-actions form{margin:0}
    .room-request-empty{padding:18px 12px;color:#667b7e;font-size:13px;text-align:center}
    .room-request-summary{margin:5px 0 0;color:#667b7e;font-size:12px}
    .room-alert{margin-bottom:15px;padding:11px 13px;border:1px solid;border-radius:6px;font-size:13px;line-height:1.5}
    .room-alert-success{border-color:#b8e6d3;background:#eaf8f1;color:#18734f}
    .room-alert-error{border-color:#f0c7c7;background:#fff0f0;color:#8a1c1c}
    .room-warning{margin:12px 0;padding:10px 12px;border-left:3px solid #c58b16;background:#fff8e8;color:#684b0a;font-size:12px;line-height:1.5}
    .room-empty{grid-column:1/-1;padding:30px 20px;border:1px dashed #c5d9de;border-radius:8px;color:#667b7e;text-align:center}
    .room-modal{position:fixed;inset:0;margin:auto;width:min(900px,calc(100vw - 32px));max-width:none;max-height:calc(100vh - 32px);padding:0;border:1px solid #d7e6ea;border-radius:12px;background:#fff;color:#0d1c1e;box-shadow:0 20px 60px rgba(13,28,30,.24);overflow:auto}
    .room-modal[open]{display:flex;flex-direction:column}
    .room-modal::backdrop{background:rgba(13,28,30,.58);backdrop-filter:blur(2px)}
    .room-modal-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 22px;border-bottom:1px solid #edf2f3}
    .room-modal-header h2{margin:0;color:#0d1c1e;font:700 22px 'Playfair Display',serif}
    .room-modal-close{display:grid;width:36px;height:36px;place-items:center;border:1px solid #d7e6ea;border-radius:7px;background:#fff;color:#536466;font:inherit;font-size:21px;cursor:pointer}
    .room-modal-close:hover{background:#f7fcfd;color:#0d1c1e}
    .room-modal-body{padding:20px 22px;overflow-y:auto}
    .room-modal-body .room-alert{margin:0 0 16px}
    .room-modal .room-form{grid-template-columns:repeat(6,minmax(0,1fr))}
    .room-modal .room-form-field-number,.room-modal .room-form-field-type{grid-column:span 3}
    .room-modal .room-form-field-capacity,.room-modal .room-form-field-rate,.room-modal .room-form-field-status{grid-column:span 2}
    .room-modal .room-form-field-library,.room-modal .room-form-field-upload{grid-column:span 3}
    .room-modal-preview{display:flex;min-height:130px;grid-column:1/-1;align-items:center;justify-content:center;padding:12px;border:1px dashed #d7e6ea;border-radius:7px;background:#f7fcfd}
    .room-modal-preview .room-image-preview{width:auto;max-width:100%;max-height:180px;margin:0}
    .room-modal-footer{display:flex;justify-content:flex-end;gap:8px;padding:15px 22px;border-top:1px solid #edf2f3}
    .room-modal-footer form{margin:0}
    .room-modal-footer .room-button{min-width:100px}
    @media(max-width:760px){.room-modal .room-form{grid-template-columns:repeat(2,minmax(0,1fr))}.room-modal .room-form-field-number,.room-modal .room-form-field-type,.room-modal .room-form-field-capacity,.room-modal .room-form-field-rate,.room-modal .room-form-field-status,.room-modal .room-form-field-library,.room-modal .room-form-field-upload{grid-column:span 1}}
    @media(max-width:900px){.rooms-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.rooms-overview{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:600px){.rooms-page{padding:23px 15px 40px}.rooms-heading{align-items:flex-start;flex-direction:column}.rooms-grid,.room-form,.room-modal .room-form{grid-template-columns:1fr}.rooms-overview{gap:8px}.rooms-stat{padding:13px}.room-form-actions{grid-column:auto}.rooms-panel{padding:16px}.room-modal .room-form-field-number,.room-modal .room-form-field-type,.room-modal .room-form-field-capacity,.room-modal .room-form-field-rate,.room-modal .room-form-field-status,.room-modal .room-form-field-library,.room-modal .room-form-field-upload{grid-column:1/-1}.room-modal-header,.room-modal-body{padding-right:17px;padding-left:17px}.room-modal-footer{padding:13px 17px}}
</style>

<main class="rooms-page">
    <header class="rooms-heading">
        <div>
            <h1>Room Management</h1>
            <p>Manage room details, availability, pricing, and tenant assignments.</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <button class="room-button room-button-primary" type="button" data-room-modal-open>+ Add New Room</button>
        </div>
    </header>

    <?php if ($success !== ''): ?>
        <div class="room-alert room-alert-success" role="status"><?= e($success); ?></div>
    <?php endif; ?>
    <?php if ($error !== '' && !$addMode): ?>
        <div class="room-alert room-alert-error" role="alert"><?= e($error); ?></div>
    <?php endif; ?>

    <dialog class="room-modal" id="addRoomModal" aria-labelledby="add-room-modal-title">
        <div class="room-modal-header">
            <h2 id="add-room-modal-title">Add New Room</h2>
            <form method="dialog">
                <button class="room-modal-close" type="submit" aria-label="Close add room dialog">&times;</button>
            </form>
        </div>
        <div class="room-modal-body">
            <?php if ($addMode && $error !== ''): ?>
                <div class="room-alert room-alert-error" role="alert"><?= e($error); ?></div>
            <?php endif; ?>
            <form method="post" class="room-form" enctype="multipart/form-data" id="add-room-form">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_rooms_csrf']); ?>">
                <input type="hidden" name="action" value="create">
                <div class="room-form-field-number">
                    <label for="add-room-number">Room Number / Name</label>
                    <input id="add-room-number" name="room_number" maxlength="20" value="<?= $addMode ? e($formValues['room_number']) : ''; ?>" placeholder="e.g. Room 4" required>
                </div>
                <div class="room-form-field-type">
                    <label for="add-room-type">Room Type / Description</label>
                    <input id="add-room-type" name="room_type" maxlength="50" value="<?= $addMode ? e($formValues['room_type']) : ''; ?>" placeholder="e.g. Aircon, Solo Room" required>
                </div>
                <div class="room-form-field-capacity">
                    <label for="add-room-capacity">Capacity</label>
                    <input id="add-room-capacity" name="capacity" type="number" min="1" step="1" value="<?= $addMode ? e($formValues['capacity']) : ''; ?>" required>
                    <p class="room-form-help">Maximum number of occupants for this room.</p>
                </div>
                <div class="room-form-field-rate">
                    <label for="add-room-rate">Price per month (₱)</label>
                    <input id="add-room-rate" name="monthly_rate" type="number" min="0" max="99999999.99" step="0.01" value="<?= $addMode ? e($formValues['monthly_rate']) : ''; ?>" required>
                </div>
                <div class="room-form-field-status">
                    <label for="add-room-status">Status</label>
                    <select id="add-room-status" name="status" required>
                        <?php foreach ($statusOptions as $statusOption): ?>
                            <?php if ($statusOption === 'Occupied') continue; ?>
                            <option value="<?= e($statusOption); ?>" <?= ($addMode ? $formValues['status'] : 'Available') === $statusOption ? 'selected' : ''; ?>><?= e($statusOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="room-form-field-library">
                    <label for="add-room-library-image">Choose a local room photo</label>
                    <select id="add-room-library-image" class="room-library-image" name="room_library_image">
                        <option value="">Use the placeholder until a photo is assigned</option>
                        <?php foreach ($roomImageLibraryFiles as $libraryImage): ?>
                            <option value="<?= e($libraryImage); ?>" <?= $addMode && ($_POST['room_library_image'] ?? '') === $libraryImage ? 'selected' : ''; ?>><?= e(pathinfo($libraryImage, PATHINFO_FILENAME)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="room-form-help">Choose a photo from the local library.</p>
                </div>
                <div class="room-form-field-upload">
                    <label for="add-room-image">Room Image (optional, JPG/PNG/WebP up to 5 MB)</label>
                    <input id="add-room-image" class="room-image-input" name="room_image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    <p class="room-form-help">Choose a local photo or upload an image, not both.</p>
                </div>
                <div class="room-modal-preview" hidden>
                    <img id="add-room-image-preview" class="room-image-preview" alt="Room image preview" hidden>
                </div>
            </form>
        </div>
        <div class="room-modal-footer">
            <form method="dialog"><button class="room-button" type="submit">Cancel</button></form>
            <button class="room-button room-button-primary" type="submit" form="add-room-form">Save Room</button>
        </div>
    </dialog>

    <section class="rooms-overview" aria-label="Room totals">
        <a class="rooms-stat <?= $filterStatus === '' ? 'selected' : ''; ?>" href="rooms.php"><span>Total Rooms</span><strong><?= (int) $roomCounts['total']; ?></strong></a>
        <a class="rooms-stat <?= $filterStatus === 'Available' ? 'selected' : ''; ?>" href="rooms.php?status=Available"><span>Available Rooms</span><strong><?= (int) $roomCounts['available']; ?></strong></a>
        <a class="rooms-stat <?= $filterStatus === 'Fully Occupied' ? 'selected' : ''; ?>" href="rooms.php?status=Fully%20Occupied"><span>Fully Occupied Rooms</span><strong><?= (int) $roomCounts['occupied']; ?></strong></a>
        <a class="rooms-stat <?= $filterStatus === 'Maintenance' ? 'selected' : ''; ?>" href="rooms.php?status=Maintenance"><span>Rooms Under Maintenance</span><strong><?= (int) $roomCounts['maintenance']; ?></strong></a>
    </section>

    <section class="rooms-panel" id="room-applications" aria-labelledby="room-applications-heading">
        <div class="rooms-section-heading" style="margin-top:0">
            <div>
                <h2 id="room-applications-heading">Room Applications</h2>
                <p class="room-request-summary"><?= $pendingRoomRequestCount; ?> pending <?= $pendingRoomRequestCount === 1 ? 'application' : 'applications'; ?></p>
            </div>
        </div>
        <?php if ($roomRequestError): ?>
            <p class="room-request-empty">Room applications are unavailable. Confirm that the room_requests migration has been applied.</p>
        <?php elseif (!$roomRequests): ?>
            <p class="room-request-empty">No room applications have been submitted.</p>
        <?php else: ?>
            <div class="room-request-list">
                <?php foreach ($roomRequests as $roomRequest): ?>
                    <?php $requestDate = strtotime($roomRequest['created_at']); ?>
                    <article class="room-request-card" id="room-request-<?= (int) $roomRequest['id']; ?>">
                        <div class="room-request-top">
                            <div>
                                <strong><?= e($roomRequest['applicant_name']); ?></strong>
                                <span>Room <?= e($roomRequest['room_number']); ?> · <?= e($roomRequest['room_type']); ?></span>
                                <span class="branch-indicator"><span class="branch-indicator-label">Branch:</span> <?= e($roomRequest['branch_name'] ?? 'Branch unavailable'); ?></span>
                            </div>
                            <span class="room-request-status <?= strtolower(e($roomRequest['status'])); ?>"><?= e($roomRequest['status']); ?></span>
                        </div>
                        <div class="room-request-meta">
                            <span>₱<?= number_format((float) $roomRequest['monthly_rate'], 2); ?>/month</span>
                            <span>Requested: <?= $requestDate ? e(date('F j, Y g:i A', $requestDate)) : e($roomRequest['created_at']); ?></span>
                        </div>
                        <details class="room-request-details" <?= $requestReviewId === (int) $roomRequest['id'] ? 'open' : ''; ?>>
                            <summary>View Application</summary>
                            <div class="room-request-detail-grid">
                                <div><strong>Applicant</strong><?= e($roomRequest['applicant_name']); ?></div>
                                <div><strong>Contact Number</strong><?= e($roomRequest['contact_number'] ?: 'Not provided'); ?></div>
                                <div><strong>Email</strong><?= e($roomRequest['email'] ?: 'Not provided'); ?></div>
                                <div><strong>Requested Room</strong>Room <?= e($roomRequest['room_number']); ?> · <?= e($roomRequest['room_type']); ?><span class="branch-indicator"><span class="branch-indicator-label">Branch:</span> <?= e($roomRequest['branch_name'] ?? 'Branch unavailable'); ?></span></div>
                                <div><strong>Capacity</strong><?= (int) $roomRequest['capacity']; ?> <?= (int) $roomRequest['capacity'] === 1 ? 'person' : 'persons'; ?></div>
                                <div><strong>Monthly Rate</strong>₱<?= number_format((float) $roomRequest['monthly_rate'], 2); ?></div>
                                <div><strong>Request Date</strong><?= $requestDate ? e(date('F j, Y g:i A', $requestDate)) : e($roomRequest['created_at']); ?></div>
                                <div><strong>Current Room Status</strong><?= e($roomRequest['room_status']); ?></div>
                                <div><strong>Tenant Status</strong><?= e(ucfirst($roomRequest['tenant_status'])); ?></div>
                                <div><strong>Application Status</strong><?= e($roomRequest['status']); ?></div>
                            </div>
                        </details>
                        <?php if ($roomRequest['status'] === 'Pending'): ?>
                            <div class="room-request-actions">
                                <form method="post" action="rooms.php#room-applications">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_rooms_csrf']); ?>">
                                    <input type="hidden" name="action" value="approve_room_request">
                                    <input type="hidden" name="request_id" value="<?= (int) $roomRequest['id']; ?>">
                                    <button class="room-button room-button-primary" type="submit">Approve</button>
                                </form>
                                <form method="post" action="rooms.php#room-applications">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_rooms_csrf']); ?>">
                                    <input type="hidden" name="action" value="reject_room_request">
                                    <input type="hidden" name="request_id" value="<?= (int) $roomRequest['id']; ?>">
                                    <button class="room-button room-button-danger" type="submit">Reject</button>
                                </form>
                            </div>
                        <?php elseif ($roomRequest['reviewed_at']): ?>
                            <?php $reviewDate = strtotime($roomRequest['reviewed_at']); ?>
                            <p class="room-request-summary">Reviewed<?= $reviewDate ? ' ' . e(date('F j, Y g:i A', $reviewDate)) : ''; ?>.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section aria-labelledby="manage-rooms-heading">
        <div class="rooms-section-heading"><h2 id="manage-rooms-heading">Manage Rooms</h2></div>
        <?php if ($editId > 0 && $editRoom): ?>
            <div class="rooms-panel">
                <h2>Edit Room <?= e($editRoom['room_number']); ?></h2>
                <p class="rooms-panel-intro">Manage room details, availability, pricing, and room photos from here.</p>
                                <form method="post" class="room-form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_rooms_csrf']); ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="room_id" value="<?= (int) $editId; ?>">
                    <div><label for="room_number">Room Number</label><input id="room_number" name="room_number" maxlength="20" value="<?= e($formValues['room_number']); ?>" required></div>
                    <div><label for="room_type">Room Type</label><input id="room_type" name="room_type" maxlength="50" value="<?= e($formValues['room_type']); ?>" required></div>
                    <div>
                        <label for="room_capacity">Capacity</label>
                        <input id="room_capacity" name="capacity" type="number" min="1" step="1" value="<?= e($formValues['capacity']); ?>" required>
                        <p class="room-form-help">Maximum number of occupants for this room.</p>
                    </div>
                    <div><label for="monthly_rate">Monthly Rate</label><input id="monthly_rate" name="monthly_rate" type="number" min="0" max="99999999.99" step="0.01" value="<?= e($formValues['monthly_rate']); ?>" required></div>
                    <div>
                        <label for="room_status">Room Status</label>
                        <select id="room_status" name="status" required>
                            <?php foreach ($statusOptions as $statusOption): ?>
                                <option value="<?= e($statusOption); ?>" <?= $formValues['status'] === $statusOption ? 'selected' : ''; ?>><?= e($statusOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="room-form-help">Choose Maintenance to block assignments. Otherwise, occupancy status is calculated from active tenant assignments.</p>
                    </div>
                    <div class="room-form-field-full">
                        <?php
                        $currentLibraryImage = $editRoom
                            && in_array(basename((string) $editRoom['image_path']), $roomImageLibraryFiles, true)
                            ? basename((string) $editRoom['image_path'])
                            : '';
                        ?>
                        <label for="room_library_image">Choose a local room photo</label>
                        <select id="room_library_image" class="room-library-image" name="room_library_image">
                            <option value="">Keep the current photo</option>
                            <?php foreach ($roomImageLibraryFiles as $libraryImage): ?>
                                <option value="<?= e($libraryImage); ?>" <?= $currentLibraryImage === $libraryImage ? 'selected' : ''; ?>><?= e(pathinfo($libraryImage, PATHINFO_FILENAME)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="room-form-help">Photos in the local library are stored in assets/images/rooms. Upload a replacement below if needed.</p>
                        <label for="room_image">Room Image (optional, JPG/PNG/WebP up to 5 MB)</label>
                        <input id="room_image" class="room-image-input" name="room_image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <p class="room-form-help">Leave both controls unchanged to keep the current photo. Uploads are limited to 5 MB.</p>
                        <img id="room-image-preview" class="room-image-preview" src="<?= $editRoomImageUrl !== null ? e($editRoomImageUrl) : ''; ?>" alt="Room image preview" <?= $editRoomImageUrl === null ? 'hidden' : ''; ?>>
                    </div>
                    <div class="room-form-actions">
                        <button class="room-button room-button-primary" type="submit">Save Changes</button>
                        <a class="room-button" href="rooms.php">Cancel</a>
                    </div>
                </form>
            </div>
        <?php elseif ($editId > 0): ?>
            <div class="room-alert room-alert-error" role="alert">The selected room could not be found.</div>
        <?php endif; ?>
    </section>

    <?php if ($assignRoomId > 0): ?>
        <?php if ($assignRoom && $assignRoom['accepts_tenants']): ?>
            <section class="rooms-panel" aria-labelledby="assign-room-heading">
                <h2 id="assign-room-heading">Assign Tenant to <?= e(roomDisplayTitle((string) $assignRoom['room_number'])); ?></h2>
                <p class="rooms-panel-intro"><?= (int) $assignRoom['available_slots']; ?> of <?= (int) $assignRoom['capacity']; ?> spaces are currently available. Only active tenants without an existing room assignment are eligible.</p>
                <?php if ($eligibleTenants): ?>
                    <form method="post" class="room-form">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_rooms_csrf']); ?>">
                        <input type="hidden" name="action" value="assign_tenant">
                        <input type="hidden" name="room_id" value="<?= (int) $assignRoom['id']; ?>">
                        <div>
                            <label for="tenant_id">Eligible Tenant</label>
                            <select id="tenant_id" name="tenant_id" required>
                                <option value="">Choose a tenant</option>
                                <?php foreach ($eligibleTenants as $tenant): ?>
                                    <option value="<?= (int) $tenant['id']; ?>"><?= e($tenant['full_name']); ?><?= $tenant['contact_number'] ? ' · ' . e($tenant['contact_number']) : ''; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="room-form-actions"><button class="room-button room-button-primary" type="submit">Confirm Assignment</button><a class="room-button" href="rooms.php">Cancel</a></div>
                    </form>
                <?php else: ?>
                    <p class="room-warning">There are no active, unassigned tenant records available. If an account exists without its tenant details, visit <a href="tenants.php#accounts-needing-record">Accounts Needing a Tenant Record</a> to add the missing tenant record.</p>
                <?php endif; ?>
            </section>
        <?php elseif (!$assignRoom): ?>
            <div class="room-alert room-alert-error" role="alert">The selected room could not be found.</div>
        <?php else: ?>
            <div class="room-alert room-alert-error" role="alert"><?= $assignRoom['status'] === 'Maintenance' ? 'A room under maintenance cannot receive a tenant.' : 'This room is already at full capacity. No available slots remain.' ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <section aria-labelledby="rooms-display-heading">
        <div class="rooms-section-heading"><h2 id="rooms-display-heading">Rooms</h2></div>
        <div class="rooms-panel">
            <p class="rooms-panel-intro">Review room details, status, tenant assignments, and pending applications here.</p>
            <div class="rooms-toolbar">
                <form class="rooms-search" method="get" action="rooms.php">
                    <?php if ($filterStatus !== ''): ?><input type="hidden" name="status" value="<?= e($filterStatus); ?>"><?php endif; ?>
                    <input type="search" name="search" value="<?= e($search); ?>" placeholder="Search room number or type" aria-label="Search room number or type">
                    <button class="room-button" type="submit">Search</button>
                </form>
                <nav class="rooms-filters" aria-label="Filter rooms by status">
                    <a class="rooms-filter <?= $filterStatus === '' ? 'selected' : ''; ?>" href="rooms.php">All Rooms</a>
                    <?php foreach ($roomFilterOptions as $statusOption): ?>
                        <a class="rooms-filter <?= $filterStatus === $statusOption ? 'selected' : ''; ?>" href="rooms.php?status=<?= rawurlencode($statusOption); ?>"><?= e($statusOption); ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <div class="rooms-grid">
                <?php if ($rooms): ?>
                    <?php foreach ($rooms as $room): ?>
                        <article class="room-card">
                            <img class="room-card-image" src="<?= e($room['image_url']); ?>" alt="Photo of <?= e(roomDisplayTitle((string) $room['room_number'])); ?>" loading="lazy">
                            <div class="room-card-top">
                                <div><h3><?= e(roomDisplayTitle((string) $room['room_number'])); ?></h3><p class="room-card-type"><?= e($room['room_type']); ?></p><span class="branch-indicator"><span class="branch-indicator-label">Branch:</span> <?= e($room['branch_name'] ?? 'Branch unavailable'); ?></span></div>
                                <span class="room-status room-status-<?= e(strtolower(str_replace(' ', '-', $room['occupancy_status']))); ?>"><?= e($room['occupancy_status']); ?></span>
                            </div>
                            <div class="room-card-content">
                                <?php if ((int) $room['pending_request_count'] > 0): ?>
                                    <a class="room-request-indicator" href="#room-applications"><?= (int) $room['pending_request_count']; ?> Pending <?= (int) $room['pending_request_count'] === 1 ? 'Request' : 'Requests'; ?></a>
                                <?php endif; ?>
                                <div class="room-card-type">Capacity: <?= (int) $room['capacity']; ?> <?= (int) $room['capacity'] === 1 ? 'person' : 'persons'; ?></div>
                                <div class="room-card-type">Occupants: <?= (int) $room['current_occupants']; ?>/<?= (int) $room['capacity']; ?> · Available slots: <?= (int) $room['available_slots']; ?></div>
                                <progress class="room-occupancy-progress" max="100" value="<?= e((string) min(100, $room['occupancy_percentage'])); ?>" aria-label="Occupancy <?= e((string) round($room['occupancy_percentage'])); ?> percent"></progress>
                                <div class="room-rate">₱<?= number_format((float) $room['monthly_rate'], 2); ?> <small>/ month</small></div>
                                <div class="room-assignee"><strong>Active Tenants:</strong> <?= $room['active_tenant_names'] !== null ? e($room['active_tenant_names']) : 'None'; ?></div>
                            </div>
                            <div class="room-card-actions">
                                <a class="room-button" href="rooms.php?edit=<?= (int) $room['id']; ?>">Edit Room</a>
                                <?php if ($room['accepts_tenants']): ?>
                                    <a class="room-button room-button-primary" href="rooms.php?assign_room=<?= (int) $room['id']; ?>">Assign Tenant</a>
                                <?php endif; ?>
                                <?php if (!(bool) $room['has_tenant'] && !(bool) $room['has_maintenance_history']): ?>
                                    <form method="post" onsubmit="return confirm('Delete this room permanently? This cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_rooms_csrf']); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="room_id" value="<?= (int) $room['id']; ?>">
                                        <button class="room-button room-button-danger" type="submit">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="room-empty">
                        <?php if ((int) $roomCounts['total'] === 0): ?>
                            No rooms have been added yet. Use Add New Room to create your first room.
                        <?php else: ?>
                            No rooms match these search or filter options.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
<script>
    const addRoomModal = document.getElementById('addRoomModal');
    const addRoomModalButton = document.querySelector('[data-room-modal-open]');
    if (addRoomModal && addRoomModalButton) {
        addRoomModalButton.addEventListener('click', () => addRoomModal.showModal());
        addRoomModal.addEventListener('click', (event) => {
            if (event.target === addRoomModal) addRoomModal.close();
        });
        if (<?= $addMode ? 'true' : 'false'; ?>) addRoomModal.showModal();
    }

    document.querySelectorAll('.room-form').forEach((roomForm) => {
        const roomImageInput = roomForm.querySelector('.room-image-input');
        const roomLibrarySelect = roomForm.querySelector('.room-library-image');
        const roomImagePreview = roomForm.querySelector('.room-image-preview');
        if (!roomImageInput || !roomImagePreview) return;

        let previewUrl = null;
        const originalPreviewUrl = roomImagePreview.getAttribute('src') || '';
        const modalPreview = roomImagePreview.closest('.room-modal-preview');
        roomImageInput.addEventListener('change', () => {
            const imageFile = roomImageInput.files && roomImageInput.files[0];
            if (!imageFile) return;
            if (roomLibrarySelect) roomLibrarySelect.value = '';
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = URL.createObjectURL(imageFile);
            roomImagePreview.src = previewUrl;
            roomImagePreview.hidden = false;
            if (modalPreview) modalPreview.hidden = false;
        });
        if (!roomLibrarySelect) return;
        roomLibrarySelect.addEventListener('change', () => {
            if (roomImageInput.files && roomImageInput.files.length) roomImageInput.value = '';
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = null;
            }
            if (roomLibrarySelect.value) {
                roomImagePreview.src = '/boarding_hauz/assets/images/rooms/' + encodeURIComponent(roomLibrarySelect.value);
                roomImagePreview.hidden = false;
                if (modalPreview) modalPreview.hidden = false;
            } else if (originalPreviewUrl) {
                roomImagePreview.src = originalPreviewUrl;
                roomImagePreview.hidden = false;
                if (modalPreview) modalPreview.hidden = false;
            } else {
                roomImagePreview.removeAttribute('src');
                roomImagePreview.hidden = true;
                if (modalPreview) modalPreview.hidden = true;
            }
        });
    });
</script>
</body>
</html>
