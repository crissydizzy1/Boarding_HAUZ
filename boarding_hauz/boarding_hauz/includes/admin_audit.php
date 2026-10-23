<?php

function recordAdminAuditEvent(
    mysqli $conn,
    int $actorUserId,
    string $action,
    string $targetType,
    ?int $targetId,
    ?string $details = null
): void {
    $stmt = $conn->prepare('
        INSERT INTO admin_audit_logs (actor_user_id, action, target_type, target_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->bind_param('issis', $actorUserId, $action, $targetType, $targetId, $details);
    $stmt->execute();
    $stmt->close();
}
