<?php
require_once __DIR__ . '/auth.php';
requireRole('tenant');

$userId = (int) $_SESSION['user_id'];

$message = '';
$messageType = '';
if (empty($_SESSION['tenant_messages_csrf'])) {
    $_SESSION['tenant_messages_csrf'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| FIND ADMIN ACCOUNT
|--------------------------------------------------------------------------
| The tenant does not choose the recipient.
| The system automatically sends messages to the admin.
|--------------------------------------------------------------------------
*/

$adminStmt = $conn->prepare("
    SELECT u.id, u.username
    FROM users u
    INNER JOIN branches b ON b.landlord_id = u.id
    INNER JOIN tenants t ON t.branch_id = b.id
    WHERE t.user_id = ? AND u.role = 'landlord'
    ORDER BY b.id
    LIMIT 1
");
$adminStmt->bind_param('i', $userId);
$adminStmt->execute();
$admin = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();
$adminId = (int) ($admin['id'] ?? 0);

$adminName = $admin['username'] ?? 'Boarding Hauz';


/*
|--------------------------------------------------------------------------
| SEND MESSAGE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {

    $messageText = trim($_POST['message'] ?? '');
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

    if (!hash_equals($_SESSION['tenant_messages_csrf'], $postedToken)) {
        $message = 'Your session could not be verified. Refresh the page and try again.';
        $messageType = 'error';
    } elseif ($adminId <= 0) {

        $message = 'Administrator account could not be found.';
        $messageType = 'error';

    } elseif ($messageText === '') {

        $message = 'Please enter a message.';
        $messageType = 'error';

    } elseif (strlen($messageText) > 2000) {

        $message = 'Message is too long. Please keep it under 2000 characters.';
        $messageType = 'error';

    } else {

        $stmt = $conn->prepare("
            INSERT INTO messages
            (
                sender_id,
                receiver_id,
                message
            )
            VALUES
            (
                ?,
                ?,
                ?
            )
        ");

        $stmt->bind_param(
            'iis',
            $userId,
            $adminId,
            $messageText
        );

        if ($stmt->execute()) {

            $stmt->close();

            /*
            |--------------------------------------------------------------------------
            | PRG REDIRECT
            |--------------------------------------------------------------------------
            | Prevents duplicate message submission when refreshing.
            |--------------------------------------------------------------------------
            */

            header('Location: tenant_messages.php?sent=1');
            exit;

        } else {

            $message = 'Unable to send your message. Please try again.';
            $messageType = 'error';

            $stmt->close();
        }
    }
}


/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGE AFTER REDIRECT
|--------------------------------------------------------------------------
*/

if (isset($_GET['sent'])) {

    $message = 'Message sent successfully.';
    $messageType = 'success';
}


/*
|--------------------------------------------------------------------------
| MARK ADMIN MESSAGES AS READ
|--------------------------------------------------------------------------
| Only messages sent TO this tenant are marked as read.
|--------------------------------------------------------------------------
*/

if ($adminId > 0) {

    $readStmt = $conn->prepare("
        UPDATE messages
        SET is_read = 1
        WHERE sender_id = ?
          AND receiver_id = ?
          AND is_read = 0
    ");

    $readStmt->bind_param(
        'ii',
        $adminId,
        $userId
    );

    $readStmt->execute();
    $readStmt->close();
}


/*
|--------------------------------------------------------------------------
| FETCH TENANT <-> ADMIN CONVERSATION
|--------------------------------------------------------------------------
| The tenant can only see messages between themselves and the admin.
|--------------------------------------------------------------------------
*/

$messages = [];

if ($adminId > 0) {

    $stmt = $conn->prepare("
        SELECT
            id,
            sender_id,
            receiver_id,
            message,
            created_at,
            is_read
        FROM messages
        WHERE
            (
                sender_id = ?
                AND receiver_id = ?
            )
            OR
            (
                sender_id = ?
                AND receiver_id = ?
            )
        ORDER BY created_at ASC
    ");

    $stmt->bind_param(
        'iiii',
        $userId,
        $adminId,
        $adminId,
        $userId
    );

    $stmt->execute();

    $messages = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| FETCH TENANT NAME
|--------------------------------------------------------------------------
*/

$tenantName = 'Tenant';

$stmt = $conn->prepare("
    SELECT full_name
    FROM tenants
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$tenantData = $stmt->get_result()->fetch_assoc();

if ($tenantData && !empty($tenantData['full_name'])) {
    $tenantName = $tenantData['full_name'];
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| TENANT HEADER
|--------------------------------------------------------------------------
*/

include __DIR__ . '/includes/tenant_header.php';
?>


<style>

/* =========================================================
   BOARDING HAUZ — TENANT MESSAGES
   ========================================================= */

:root {

    --msg-primary: #22b486;
    --msg-primary-dark: #1aa27a;
    --msg-primary-container: #2ec49b;

    --msg-secondary: #236b67;
    --msg-secondary-container: #1f7e79;

    --msg-background: #edf9fc;
    --msg-surface: #ffffff;

    --msg-text: #0d1c1e;
    --msg-outline: #d7e6ea;

    --msg-tertiary: #dff3f7;
    --msg-error: #ba1a1a;

    --msg-heading: 'Playfair Display', Georgia, serif;
    --msg-body: 'Inter', 'Segoe UI', sans-serif;
}


/* =========================================================
   PAGE
   ========================================================= */

.messages-page {

    min-height: calc(100vh - 80px);

    background: var(--msg-background);

    padding: 42px 20px 60px;

    font-family: var(--msg-body);

    color: var(--msg-text);
}


.messages-container {

    width: min(1100px, 100%);

    margin: 0 auto;
}


/* =========================================================
   PAGE HEADER
   ========================================================= */

/* =========================================================
   MESSAGES HERO
   ========================================================= */

.messages-hero {

    margin-bottom: 28px;

    background: #115c54;

    border: 1px solid #0d514a;

    border-radius: 18px;

    padding: 36px 38px;

    color: #ffffff;

    box-shadow: 0 8px 24px rgba(35, 107, 103, 0.12);
}


.messages-hero-content {

    max-width: 720px;
}


.messages-hero-badge {

    display: inline-flex;

    align-items: center;

    padding: 7px 13px;

    margin-bottom: 15px;

    border-radius: 20px;

    background: rgba(255, 255, 255, 0.14);

    border: 1px solid rgba(255, 255, 255, 0.12);

    color: #ffffff;

    font-family: var(--msg-body);

    font-size: 12px;

    font-weight: 700;
}


.messages-hero h1 {

    margin: 0 0 8px;

    color: #ffffff;

    font-family: var(--msg-heading);

    font-size: 38px;

    line-height: 1.2;

    font-weight: 700;
}


.messages-hero p {

    margin: 0;

    max-width: 600px;

    color: rgba(255, 255, 255, 0.82);

    font-family: var(--msg-body);

    font-size: 14px;

    line-height: 1.6;
}
/* =========================================================
   ALERT
   ========================================================= */

.message-alert {

    padding: 13px 16px;

    border-radius: 10px;

    margin-bottom: 20px;

    font-size: 14px;

    border: 1px solid;
}


.message-alert.success {

    background: #e4f7ef;

    color: var(--msg-secondary);

    border-color: #b9ead8;
}


.message-alert.error {

    background: #fdeaea;

    color: var(--msg-error);

    border-color: #f3c4c4;
}


/* =========================================================
   MESSAGING LAYOUT
   ========================================================= */

.messages-layout {

    display: grid;

    grid-template-columns: 280px 1fr;

    min-height: 600px;

    background: var(--msg-surface);

    border: 1px solid var(--msg-outline);

    border-radius: 16px;

    overflow: hidden;

    box-shadow: 0 5px 20px rgba(35, 107, 103, 0.06);
}


/* =========================================================
   CONVERSATION SIDEBAR
   ========================================================= */

.conversations {

    border-right: 1px solid var(--msg-outline);

    background: #fbfefe;
}


.conversations-title {

    padding: 22px 20px 16px;

    font-family: var(--msg-heading);

    font-size: 19px;

    color: var(--msg-secondary);

    border-bottom: 1px solid var(--msg-outline);
}


.conversation-item {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 17px 18px;

    background: var(--msg-surface);

    border-left: 3px solid var(--msg-primary);

    cursor: default;
}


.conversation-avatar {

    width: 42px;

    height: 42px;

    border-radius: 50%;

    display: flex;

    align-items: center;

    justify-content: center;

    flex-shrink: 0;

    background: var(--msg-tertiary);

    color: var(--msg-secondary);

    font-weight: 800;

    font-size: 15px;
}


.conversation-info {

    min-width: 0;

    flex: 1;
}


.conversation-name {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 8px;

    font-size: 14px;

    font-weight: 700;

    color: var(--msg-text);
}


.conversation-preview {

    margin-top: 4px;

    font-size: 12px;

    color: #6b7f83;

    white-space: nowrap;

    overflow: hidden;

    text-overflow: ellipsis;
}


/* =========================================================
   UNREAD DOT
   ========================================================= */

.unread-dot {

    width: 8px;

    height: 8px;

    border-radius: 50%;

    background: var(--msg-primary);

    flex-shrink: 0;
}


/* =========================================================
   CHAT AREA
   ========================================================= */

.chat-area {

    display: flex;

    flex-direction: column;

    min-width: 0;

    background: var(--msg-surface);
}


/* =========================================================
   CHAT HEADER
   ========================================================= */

.chat-header {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 18px 22px;

    border-bottom: 1px solid var(--msg-outline);
}


.chat-avatar {

    width: 40px;

    height: 40px;

    border-radius: 50%;

    background: var(--msg-primary);

    color: white;

    display: flex;

    align-items: center;

    justify-content: center;

    font-weight: 800;

    font-size: 14px;
}


.chat-header-info h2 {

    margin: 0;

    font-size: 15px;

    font-weight: 700;

    color: var(--msg-text);
}


.chat-header-info p {

    margin: 3px 0 0;

    font-size: 12px;

    color: var(--msg-secondary);
}


/* =========================================================
   CHAT MESSAGES
   ========================================================= */

.chat-messages {

    flex: 1;

    padding: 24px;

    overflow-y: auto;

    background: #fbfefe;

    min-height: 430px;

    max-height: 500px;
}


.chat-empty {

    height: 100%;

    min-height: 380px;

    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    text-align: center;

    padding: 30px;
}


.chat-empty-icon {

    width: 58px;

    height: 58px;

    border-radius: 50%;

    background: var(--msg-tertiary);

    color: var(--msg-secondary);

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 24px;

    margin-bottom: 14px;
}


.chat-empty h3 {

    font-family: var(--msg-heading);

    color: var(--msg-secondary);

    margin: 0 0 6px;

    font-size: 20px;
}


.chat-empty p {

    margin: 0;

    color: #718387;

    font-size: 13px;

    max-width: 300px;

    line-height: 1.5;
}


/* =========================================================
   MESSAGE ROW
   ========================================================= */

.message-row {

    display: flex;

    margin-bottom: 17px;
}


.message-row.tenant {

    justify-content: flex-end;
}


.message-row.admin {

    justify-content: flex-start;
}


.message-content {

    max-width: min(70%, 520px);
}


/* =========================================================
   BUBBLE
   ========================================================= */

.message-bubble {

    padding: 11px 14px;

    border-radius: 14px;

    font-size: 13px;

    line-height: 1.5;

    word-break: break-word;
}


.message-row.admin .message-bubble {

    background: var(--msg-tertiary);

    color: var(--msg-text);

    border-bottom-left-radius: 4px;
}


.message-row.tenant .message-bubble {

    background: var(--msg-primary);

    color: white;

    border-bottom-right-radius: 4px;
}


.message-time {

    margin-top: 5px;

    font-size: 10px;

    color: #849397;
}


.message-row.tenant .message-time {

    text-align: right;
}


/* =========================================================
   MESSAGE INPUT
   ========================================================= */

.message-form {

    padding: 16px 18px;

    border-top: 1px solid var(--msg-outline);

    background: var(--msg-surface);
}


.message-form-inner {

    display: flex;

    gap: 10px;

    align-items: flex-end;
}


.message-input {

    flex: 1;

    min-height: 44px;

    max-height: 120px;

    resize: vertical;

    padding: 12px 14px;

    border: 1px solid var(--msg-outline);

    border-radius: 10px;

    background: #f8fcfd;

    color: var(--msg-text);

    font-family: var(--msg-body);

    font-size: 13px;

    outline: none;

    box-sizing: border-box;
}


.message-input:focus {

    border-color: var(--msg-primary);

    background: white;

    box-shadow: 0 0 0 3px rgba(34, 180, 134, 0.10);
}


.message-input::placeholder {

    color: #819296;
}


.btn-send {

    height: 44px;

    padding: 0 20px;

    border: none;

    border-radius: 9px;

    background: var(--msg-primary);

    color: white;

    font-family: var(--msg-body);

    font-size: 13px;

    font-weight: 700;

    cursor: pointer;

    transition: 0.2s ease;
}


.btn-send:hover {

    background: var(--msg-primary-dark);

    transform: translateY(-1px);
}


/* =========================================================
   RESPONSIVE
   ========================================================= */

@media (max-width: 800px) {

    .messages-layout {

        grid-template-columns: 1fr;

    }


    .conversations {

        border-right: none;

        border-bottom: 1px solid var(--msg-outline);

    }


    .conversation-item {

        border-left: none;

        border-bottom: 3px solid var(--msg-primary);

    }


    .chat-messages {

        min-height: 400px;

    }
}


@media (max-width: 600px) {

    .messages-page {

        padding: 28px 12px 40px;

    }


   .messages-hero {

    padding: 28px 22px;

    border-radius: 15px;

}


.messages-hero h1 {

    font-size: 30px;

}


.messages-hero p {

    font-size: 13px;

}


    .chat-messages {

        padding: 18px 14px;

    }


    .message-content {

        max-width: 85%;

    }


    .message-form {

        padding: 12px;

    }


    .message-form-inner {

        align-items: stretch;

    }


    .message-input {

        min-width: 0;

    }


    .btn-send {

        padding: 0 15px;

    }

}

</style>


<main class="messages-page">

    <div class="messages-container">

        <!-- =================================================
             PAGE HEADER
             ================================================= -->

      <header class="messages-hero">

    <div class="messages-hero-content">

        <span class="messages-hero-badge">
            💬 Tenant Support
        </span>

        <h1>
            Messages
        </h1>

        <p>
            Contact your boarding house administrator for questions,
            concerns, or assistance.
        </p>

    </div>

</header>


        <!-- =================================================
             ALERT
             ================================================= -->

        <?php if ($message): ?>

            <div class="message-alert <?php echo e($messageType); ?>">

                <?php echo e($message); ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             MESSAGING LAYOUT
             ================================================= -->

        <section class="messages-layout">


            <!-- =================================================
                 CONVERSATIONS
                 ================================================= -->

            <aside class="conversations">

                <div class="conversations-title">
                    Conversations
                </div>


                <div class="conversation-item">

                    <div class="conversation-avatar">
                        BH
                    </div>


                    <div class="conversation-info">

                        <div class="conversation-name">

                            <span>
                                Boarding Hauz
                            </span>

                        </div>


                        <div class="conversation-preview">

                            <?php if (!empty($messages)): ?>

                                <?php
                                $lastMessage =
                                    end($messages);

                                echo e(
                                    mb_strimwidth(
                                        $lastMessage['message'],
                                        0,
                                        55,
                                        '...'
                                    )
                                );
                                ?>

                            <?php else: ?>

                                No messages yet.

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </aside>


            <!-- =================================================
                 CHAT
                 ================================================= -->

            <section class="chat-area">


                <!-- CHAT HEADER -->

                <header class="chat-header">

                    <div class="chat-avatar">
                        BH
                    </div>


                    <div class="chat-header-info">

                        <h2>
                            Boarding Hauz
                        </h2>

                        <p>
                            Administrator
                        </p>

                    </div>

                </header>


                <!-- =================================================
                     CHAT MESSAGES
                     ================================================= -->

                <div class="chat-messages">


                    <?php if (empty($messages)): ?>

                        <div class="chat-empty">

                            <div class="chat-empty-icon">
                                💬
                            </div>

                            <h3>
                                No messages yet.
                            </h3>

                            <p>
                                Send a message to contact
                                the boarding house administrator.
                            </p>

                        </div>


                    <?php else: ?>


                        <?php foreach ($messages as $msg): ?>

                            <?php
                            $isTenant =
                                (int) $msg['sender_id'] === $userId;
                            ?>


                            <div
                                class="message-row <?php echo $isTenant ? 'tenant' : 'admin'; ?>"
                            >

                                <div class="message-content">


                                    <div class="message-bubble">

                                        <?php
                                        echo nl2br(
                                            e($msg['message'])
                                        );
                                        ?>

                                    </div>


                                    <div class="message-time">

                                        <?php
                                        echo date(
                                            'M d, Y • g:i A',
                                            strtotime(
                                                $msg['created_at']
                                            )
                                        );
                                        ?>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>


                    <?php endif; ?>


                </div>


                <!-- =================================================
                     MESSAGE FORM
                     ================================================= -->

                <form
                    method="POST"
                    class="message-form"
                >
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['tenant_messages_csrf']); ?>">

                    <div class="message-form-inner">

                        <textarea
                            name="message"
                            class="message-input"
                            placeholder="Type a message..."
                            maxlength="2000"
                            required
                        ></textarea>


                        <button
                            type="submit"
                            name="send_message"
                            class="btn-send"
                        >
                            Send
                        </button>

                    </div>

                </form>

            </section>

        </section>

    </div>

</main>


<script>

/*
|--------------------------------------------------------------------------
| KEEP CHAT SCROLLED TO THE MOST RECENT MESSAGE
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', function () {

    const chatMessages =
        document.querySelector('.chat-messages');

    if (chatMessages) {

        chatMessages.scrollTop =
            chatMessages.scrollHeight;

    }

});

</script>


<?php
include __DIR__ . '/includes/footer.php';
?>  