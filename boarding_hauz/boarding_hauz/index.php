<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/room_images.php';
require_once __DIR__ . '/includes/room_display.php';
require_once __DIR__ . '/includes/room_occupancy.php';

$recommendedRooms = [];
$featuredRoom = null;
$roomQueryError = false;
$publicBranches = [];
$branchQueryError = false;

try {
    $roomStmt = $conn->prepare("
        SELECT r.id, b.id AS branch_id, r.room_number, r.room_type, r.capacity, r.monthly_rate, r.status, r.image_path,
               b.branch_name
        FROM rooms r
        INNER JOIN branches b ON b.id = r.branch_id
        INNER JOIN users u ON u.id = b.landlord_id AND u.role = 'landlord' AND u.status = 'active'
        ORDER BY r.room_number, r.id
        LIMIT 3
    ");
    if (!$roomStmt) {
        throw new mysqli_sql_exception($conn->error);
    }
    $roomStmt->execute();
    $roomResult = $roomStmt->get_result();
    $recommendedRooms = attachRoomOccupancy($conn, $roomResult->fetch_all(MYSQLI_ASSOC));
    foreach ($recommendedRooms as &$room) {
        $room['image_url'] = getRoomImageUrlForDisplay((int) $room['id'], $room['image_path']);
    }
    unset($room);
    $roomStmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Landing page room query failed: ' . $exception->getMessage());
    $roomQueryError = true;
}

try {
    $branchStmt = $conn->prepare("
        SELECT b.id, b.branch_name, b.location,
            COUNT(DISTINCT CASE
                WHEN COALESCE(r.status, 'Available') <> 'Maintenance'
                    AND COALESCE(r.status, 'Available') NOT IN ('Occupied', 'Full')
                    AND r.capacity > COALESCE(occupancy.active_occupants, 0)
                THEN r.id
            END) AS available_rooms
        FROM branches b
        INNER JOIN users u ON u.id = b.landlord_id AND u.role = 'landlord' AND u.status = 'active'
        LEFT JOIN rooms r ON r.branch_id = b.id
        LEFT JOIN (
            SELECT room_id, COUNT(*) AS active_occupants
            FROM tenant_leases
            WHERE status = 'active'
            GROUP BY room_id
        ) occupancy ON occupancy.room_id = r.id
        GROUP BY b.id, b.branch_name, b.location
        HAVING available_rooms > 0
        ORDER BY b.branch_name, b.id
    ");
    $branchStmt->execute();
    $publicBranches = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $branchStmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Landing page branch query failed: ' . $exception->getMessage());
    $branchQueryError = true;
}

$featuredRoom = $recommendedRooms[0] ?? null;

require_once __DIR__ . '/includes/header.php';
?>

<main class="home-page">
<section class="hero">
    <div class="hero-container">
        <div class="hero-content">
            <span class="hero-badge">Elevated Living Across South Cotabato</span>

            <h1>
                Welcome to <span class="highlight">Boarding Hauz</span>
            </h1>

            <p>
                Find your comfortable, safe, and affordable room today.
                Designed for modern urban professionals and students
                seeking a friction-free home.
            </p>

            <div class="hero-buttons">
                <a href="rooms.php" class="btn btn-primary btn-lg">
                    Browse Rooms →
                </a>

                <a href="contact.php" class="btn btn-dark btn-lg">
                    Inquire Now
                </a>
            </div>
        </div>

        <div class="hero-image">
            <?php if ($featuredRoom): ?>
                <img class="image-placeholder hero-room-image" src="<?= e($featuredRoom['image_url']); ?>" alt="Photo of <?= e(roomDisplayTitle($featuredRoom['room_number'])); ?>">
            <?php elseif (!$roomQueryError): ?>
                <img class="image-placeholder hero-room-image" src="/boarding_hauz/assets/images/House-Bedroom-3-scaled.jpg" alt="A bright furnished bedroom">
            <?php else: ?>
                <div class="image-placeholder">Boarding Hauz</div>
            <?php endif; ?>

            <a class="hero-image-card home-featured-card" href="rooms.php">
                <div>
                    <?php if ($featuredRoom): ?>
                        <strong><?= e(roomDisplayTitle($featuredRoom['room_number'])); ?></strong>
                        <p><?= e($featuredRoom['room_type']); ?></p>
                        <span class="branch-indicator"><span class="branch-indicator-label">Branch:</span> <?= e($featuredRoom['branch_name'] ?? 'Branch unavailable'); ?></span>
                    <?php else: ?>
                        <strong>Find your next home</strong>
                        <p><?= $roomQueryError ? 'Room details are temporarily unavailable.' : 'Explore comfortable rooms across South Cotabato.'; ?></p>
                    <?php endif; ?>
                </div>

                <span class="price-tag">
                    <?= $featuredRoom ? '₱' . number_format((float) $featuredRoom['monthly_rate'], 2) . ' / mo' : 'View rooms'; ?>
                </span>
            </a>
        </div>
    </div>
</section>

<section class="branch-explore-section" aria-labelledby="branch-explore-title">
    <div class="section-container">
        <div class="branch-explore-heading">
            <div>
                <span class="hero-badge">BOARDING HAUZ LOCATIONS</span>
                <h2 id="branch-explore-title">Explore Our Branches</h2>
                <p>Choose a branch to see its rooms and current availability.</p>
            </div>
            <a href="rooms.php" class="link-arrow">Browse all rooms →</a>
        </div>

        <?php if ($branchQueryError): ?>
            <p class="home-room-empty" role="alert">Branch listings are temporarily unavailable. Please try again later.</p>
        <?php elseif ($publicBranches): ?>
            <div class="branch-explore-grid">
                <?php foreach ($publicBranches as $branch): ?>
                    <article class="branch-explore-card">
                        <img
                        src="/boarding_hauz/assets/images/room-placeholder.svg"
                            alt="Boarding Hauz branch photo placeholder"
                            loading="lazy">
                        <div class="branch-explore-card-content">
                            <h3><?= e($branch['branch_name']); ?></h3>
                            <p><?= e($branch['location']); ?></p>
                            <span class="branch-availability"><?= (int) $branch['available_rooms']; ?> <?= (int) $branch['available_rooms'] === 1 ? 'room' : 'rooms'; ?> with space available</span>
                            <a class="public-room-button" href="rooms.php?branch_id=<?= (int) $branch['id']; ?>">View Rooms</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="home-room-empty">No branches currently have rooms with available spaces.</p>
        <?php endif; ?>
    </div>
</section>

<section class="rooms-section">
    <div class="section-container">

        <div class="section-header-row">
            <h2 class="section-title-left">Recommended Rooms</h2>

            <a href="rooms.php" class="link-arrow">
                Explore all rooms →
            </a>
        </div>

        <div class="rooms-grid public-room-grid">
            <?php if ($recommendedRooms): ?>
                <?php foreach ($recommendedRooms as $room): ?>
                    <article class="public-room-card">
                        <div class="public-room-image">
                            <img src="<?= e($room['image_url']); ?>" alt="Photo of <?= e(roomDisplayTitle($room['room_number'])); ?>" loading="lazy">
                        </div>

                        <div class="public-room-top">
                            <div>
                                <h3><?= e(roomDisplayTitle($room['room_number'])); ?></h3>
                                <p><?= e($room['room_type']); ?></p>
                                <span class="branch-indicator"><span class="branch-indicator-label">Branch:</span> <?= e($room['branch_name'] ?? 'Branch unavailable'); ?></span>
                            </div>
                            <span class="public-room-status public-room-status-<?= e(strtolower(str_replace(' ', '-', $room['occupancy_status']))); ?>"><?= e($room['occupancy_status']); ?></span>
                        </div>

                        <div class="public-room-content">
                            <div class="home-room-card-details">
                                <span class="public-room-capacity"><span class="material-symbols-outlined" aria-hidden="true">person</span>Capacity: <?= (int) $room['capacity']; ?> <?= (int) $room['capacity'] === 1 ? 'Person' : 'Persons'; ?></span>
                                <span class="public-room-capacity"><?= (int) $room['available_slots']; ?> available <?= (int) $room['available_slots'] === 1 ? 'slot' : 'slots'; ?></span>
                                <span class="public-room-rate">₱<?= number_format((float) $room['monthly_rate'], 2); ?><small>/mo</small></span>
                            </div>
                            <div class="public-room-actions">
                                <a href="room-details.php?id=<?= (int) $room['id']; ?>&amp;branch_id=<?= (int) $room['branch_id']; ?>" class="public-room-button">View Details &amp; Contact <span aria-hidden="true">›</span></a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php elseif ($roomQueryError): ?>
                <p class="no-results-msg home-room-empty">Room listings are temporarily unavailable. Please try again later.</p>
            <?php else: ?>
                <p class="no-results-msg home-room-empty">
                    New room listings are coming soon. Browse the directory or <a href="contact.php">contact us</a> for availability.
                </p>
            <?php endif; ?>

        </div>


        <div class="info-banner home-rooms-banner">
            <p>
                Looking for more options or specific room features?<br>
                <span>Browse our complete directory of rooms and check their current status.</span>
            </p>

            <a href="rooms.php" class="btn btn-primary btn-lg">
                View all available rooms →
            </a>
        </div>

    </div>
</section>
 </main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>