<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/room_images.php';
require_once __DIR__ . '/includes/room_display.php';
require_once __DIR__ . '/includes/room_occupancy.php';

$legacyRoomValue = is_scalar($_GET['room'] ?? null) ? (string) $_GET['room'] : '';
if (ctype_digit($legacyRoomValue) && (int) $legacyRoomValue > 0) {
    $legacyBranchId = roomBranchId((int) $legacyRoomValue);
    $branchQuery = $legacyBranchId === null ? '' : '&branch_id=' . $legacyBranchId;
    header('Location: /boarding_hauz/room-details.php?id=' . (int) $legacyRoomValue . $branchQuery);
    exit;
}

$rooms = [];
$roomQueryError = false;
$branchQueryError = false;
$branchSelectionError = '';
$publicBranches = [];
$selectedBranch = null;
$branchParameterProvided = array_key_exists('branch_id', $_GET);
$branchParameter = is_scalar($_GET['branch_id'] ?? null) ? (string) $_GET['branch_id'] : '';
$selectedBranchId = 0;

try {
    $branchStmt = $conn->prepare("
        SELECT b.id, b.branch_name, b.location
        FROM branches b
        INNER JOIN users u ON u.id = b.landlord_id
        WHERE u.role = 'landlord' AND u.status = 'active'
          AND EXISTS (
              SELECT 1
              FROM rooms r
              WHERE r.branch_id = b.id
                AND COALESCE(r.status, 'Available') <> 'Maintenance'
                AND COALESCE(r.status, 'Available') NOT IN ('Occupied', 'Full')
                AND r.capacity > (
                    SELECT COUNT(*)
                  FROM tenant_leases l
                  WHERE l.room_id = r.id AND l.status = 'active'
                )
          )
        ORDER BY b.branch_name, b.id
    ");
    $branchStmt->execute();
    $publicBranches = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $branchStmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Public branch directory query failed: ' . $exception->getMessage());
    $branchQueryError = true;
}

if ($branchParameterProvided) {
    if (!ctype_digit($branchParameter) || (int) $branchParameter <= 0) {
        $branchSelectionError = 'Choose a valid branch from the directory.';
    } else {
        $selectedBranchId = (int) $branchParameter;
        try {
            $branchStmt = $conn->prepare("
                SELECT b.id, b.branch_name, b.location
                FROM branches b
                INNER JOIN users u ON u.id = b.landlord_id
                WHERE b.id = ? AND u.role = 'landlord' AND u.status = 'active'
                LIMIT 1
            ");
            $branchStmt->bind_param('i', $selectedBranchId);
            $branchStmt->execute();
            $selectedBranch = $branchStmt->get_result()->fetch_assoc();
            $branchStmt->close();
            if (!$selectedBranch) {
                $branchSelectionError = 'That branch is unavailable. Choose a branch from the directory.';
            }
        } catch (mysqli_sql_exception $exception) {
            error_log('Selected public branch query failed: ' . $exception->getMessage());
            $branchSelectionError = 'Branch details are temporarily unavailable.';
        }
    }
}

if ($branchSelectionError === '' && !$branchQueryError) {
    try {
        $roomSql = "
            SELECT r.id, r.room_number, r.room_type, r.capacity, r.monthly_rate, r.status, r.image_path,
                   b.id AS branch_id, b.branch_name, b.location AS branch_location
            FROM rooms r
            INNER JOIN branches b ON b.id = r.branch_id
            INNER JOIN users u ON u.id = b.landlord_id AND u.role = 'landlord' AND u.status = 'active'
        ";
        if ($selectedBranchId > 0) {
            $roomSql .= ' WHERE r.branch_id = ?';
        }
        $roomSql .= ' ORDER BY b.branch_name, r.room_number, r.id';
        $roomStmt = $conn->prepare($roomSql);
        if (!$roomStmt) {
            throw new mysqli_sql_exception($conn->error);
        }
        if ($selectedBranchId > 0) {
            $roomStmt->bind_param('i', $selectedBranchId);
        }
        $roomStmt->execute();
        $roomResult = $roomStmt->get_result();
        $rooms = attachRoomOccupancy($conn, $roomResult->fetch_all(MYSQLI_ASSOC));
        foreach ($rooms as &$room) {
            $room['image_url'] = getRoomImageUrlForDisplay((int) $room['id'], $room['image_path']);
        }
        unset($room);
        $roomStmt->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('Available rooms query failed: ' . $exception->getMessage());
        $roomQueryError = true;
    }
}
$roomCapacities = array_values(array_unique(array_map('intval', array_column($rooms, 'capacity'))));
sort($roomCapacities, SORT_NUMERIC);

require_once __DIR__ . '/includes/header.php';
?>

<main class="available-rooms-page">
    <section class="rooms-directory-hero">
        <div class="rooms-directory-hero-content">
            <span class="rooms-directory-eyebrow">BOARDING HAUZ PROPERTIES</span>
            <h1>Explore Available Rooms</h1>
            <p>Find a comfortable, affordable room that suits your needs. Browse current room details and availability below.</p>
        </div>
    </section>

    <section class="rooms-filter-shell" aria-label="Filter rooms">
        <div class="rooms-filter-panel">
            <form class="rooms-branch-selector" method="get" action="rooms.php">
                <label for="branchFilter">View rooms by branch</label>
                <div class="rooms-branch-selector-controls">
                    <select id="branchFilter" name="branch_id">
                        <option value="">All branches</option>
                        <?php foreach ($publicBranches as $branch): ?>
                            <option value="<?= (int) $branch['id']; ?>" <?= $selectedBranchId === (int) $branch['id'] ? 'selected' : ''; ?>>
                                <?= e($branch['branch_name']); ?> — <?= e($branch['location']); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ($selectedBranch && !in_array((int) $selectedBranch['id'], array_map(static fn (array $branch): int => (int) $branch['id'], $publicBranches), true)): ?>
                            <option value="<?= (int) $selectedBranch['id']; ?>" selected><?= e($selectedBranch['branch_name']); ?> — <?= e($selectedBranch['location']); ?></option>
                        <?php endif; ?>
                    </select>
                    <button class="rooms-apply-filters" type="submit">View Branch</button>
                    <?php if ($selectedBranch): ?>
                        <a class="rooms-clear-branch" href="rooms.php">All branches</a>
                    <?php endif; ?>
                </div>
            </form>
            <label class="rooms-search-field" for="searchInput">
                <span class="material-symbols-outlined" aria-hidden="true">search</span>
                <input id="searchInput" type="search" placeholder="Search room number or type..." autocomplete="off">
            </label>

            <div class="rooms-filter-controls">
                <label class="visually-hidden" for="capacityFilter">Filter by capacity</label>
                <select id="capacityFilter">
                    <option value="all">Capacity (All)</option>
                    <?php foreach ($roomCapacities as $roomCapacity): ?>
                        <option value="<?= $roomCapacity; ?>"><?= $roomCapacity; ?> <?= $roomCapacity === 1 ? 'Person' : 'Persons'; ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="visually-hidden" for="priceFilter">Filter by price</label>
                <select id="priceFilter">
                    <option value="all">Price Range (All)</option>
                    <option value="low">Below ₱2,500</option>
                    <option value="mid">₱2,500 - ₱4,000</option>
                    <option value="high">Above ₱4,000</option>
                </select>

                <label class="visually-hidden" for="statusFilter">Filter by room status</label>
                <select id="statusFilter">
                    <option value="all">Status (All)</option>
                    <option value="available">Available</option>
                    <option value="partially-occupied">Partially Occupied</option>
                    <option value="fully-occupied">Fully Occupied</option>
                    <option value="maintenance">Maintenance</option>
                </select>

                <button id="applyFiltersBtn" class="rooms-apply-filters" type="button">
                    <span class="material-symbols-outlined" aria-hidden="true">tune</span>
                    Apply Filters
                </button>
            </div>
        </div>
    </section>

    <section class="rooms-catalog" aria-labelledby="rooms-catalog-title">
        <div class="rooms-results-heading">
            <div>
                <span class="rooms-directory-eyebrow">FIND YOUR NEXT HOME</span>
                <h2 id="rooms-catalog-title"><?= $selectedBranch ? 'Rooms at ' . e($selectedBranch['branch_name']) : 'Room Directory'; ?></h2>
                <?php if ($selectedBranch): ?>
                    <p class="rooms-selected-branch"><?= e($selectedBranch['location']); ?> · <a href="rooms.php">Browse all branches</a></p>
                <?php endif; ?>
            </div>
            <?php if (!$roomQueryError): ?>
                <p id="roomsCount" class="rooms-results-count">Showing <?= count($rooms); ?> <?= count($rooms) === 1 ? 'room' : 'rooms'; ?></p>
            <?php endif; ?>
        </div>

        <?php if ($branchSelectionError !== ''): ?>
            <p class="rooms-empty-message" role="alert"><?= e($branchSelectionError); ?></p>
        <?php elseif ($branchQueryError): ?>
            <p class="rooms-empty-message" role="alert">Branch listings are temporarily unavailable. Please try again later.</p>
        <?php elseif ($roomQueryError): ?>
            <p class="rooms-empty-message" role="alert">We are unable to load rooms right now. Please try again later.</p>
        <?php elseif (!$rooms): ?>
            <p class="rooms-empty-message">No rooms are currently listed. Check back soon or <a href="contact.php">contact Boarding Hauz</a> for assistance.</p>
        <?php else: ?>
            <div class="rooms-design-grid" id="roomsGrid">
                <?php foreach ($rooms as $room): ?>
                    <?php
                    $roomTitle = roomDisplayTitle($room['room_number']);
                    $capacity = (int) $room['capacity'];
                    $status = strtolower((string) $room['occupancy_status']);
                    ?>
                    <article class="public-room-card rooms-design-card"
                        data-search="<?= e(strtolower($room['room_number'] . ' ' . $room['room_type'] . ' ' . $room['branch_name'] . ' ' . $room['branch_location'])); ?>"
                        data-capacity="<?= $capacity; ?>"
                        data-price="<?= e($room['monthly_rate']); ?>"
                        data-status="<?= e($status); ?>">
                        <div class="rooms-design-image">
                            <img src="<?= e($room['image_url']); ?>" alt="Photo of <?= e($roomTitle); ?>" loading="lazy">
                            <span class="public-room-status public-room-status-<?= e(strtolower(str_replace(' ', '-', $room['occupancy_status']))); ?> rooms-design-status"><?= e($room['occupancy_status']); ?></span>
                        </div>

                        <div class="rooms-design-card-body">
                            <div class="rooms-design-title-row">
                                <div>
                                    <h3><?= e($roomTitle); ?></h3>
                                    <p><?= e($room['room_type']); ?></p>
                                    <span class="branch-indicator"><span class="branch-indicator-label">Branch:</span> <?= e($room['branch_name'] ?? 'Branch unavailable'); ?></span>
                                    <span class="room-branch-location"><?= e($room['branch_location']); ?></span>
                                </div>
                                <p class="rooms-design-price">₱<?= number_format((float) $room['monthly_rate'], 2); ?><small>/mo</small></p>
                            </div>

                            <div class="rooms-design-meta">
                                <span><span class="material-symbols-outlined" aria-hidden="true">groups</span><?= $room['current_occupants']; ?>/<?= $capacity; ?> occupants</span>
                                <span><span class="material-symbols-outlined" aria-hidden="true">door_front</span><?= $room['available_slots']; ?> slots available</span>
                            </div>

                            <a class="rooms-design-action" href="room-details.php?id=<?= (int) $room['id']; ?>&amp;branch_id=<?= (int) $room['branch_id']; ?>">
                                <?= $room['accepts_tenants'] ? 'View Details &amp; Apply' : 'View Room Details'; ?>
                                <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <p id="noResultsMsg" class="rooms-empty-message" style="display:none;">No rooms match your filters. Try adjusting your search.</p>
        <?php endif; ?>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="/boarding_hauz/assets/js/script.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/script.js'); ?>"></script>
