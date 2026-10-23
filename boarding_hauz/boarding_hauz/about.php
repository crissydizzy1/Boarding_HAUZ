<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/header.php';
?>

<section class="about-hero">
    <div class="section-container about-hero-inner">
        <span class="eyebrow">WELCOME TO BOARDING HAUZ</span>
        <h1>ABOUT BOARDING HAUZ</h1>
        <p>Your trusted home away from home.</p>
    </div>
</section>

<section class="story-section">
    <div class="section-container story-grid">
        <article class="story-card story-card-main">
            <div class="icon-box">📖</div>
            <h2>Our Story</h2>
            <p>Founded to serve students and young professionals across South Cotabato, Boarding Hauz provides accessible, secure, and modern living spaces. We eliminate the stress of searching for a comfortable home by matching tenants with clean, well-maintained rooms equipped with essential amenities.</p>
            <div class="established-tag">
                <span class="check-mark">✓</span> Serving South Cotabato
            </div>
        </article>

        <div class="story-side">
            <article class="story-card">
                <div class="icon-box">🎯</div>
                <h3>Our Mission</h3>
                <p>To deliver affordable, comfortable, and safe living spaces backed by reliable customer service and a hassle-free rental management system.</p>
            </article>

            <article class="story-card">
                <div class="icon-box">🔭</div>
                <h3>Our Vision</h3>
                <p>To become South Cotabato's premier student and professional living portal, renowned for quality accommodations and seamless tenant experience.</p>
            </article>
        </div>
    </div>
</section>

<section class="features-section">
    <div class="section-container">
        <h2 class="section-title">WHY CHOOSE BOARDING HAUZ?</h2>
        <p class="section-subtitle">Everything you need for a comfortable, worry-free stay in Tagum City.</p>

        <div class="features-grid">
            <article class="feature-card">
                <div class="icon-box icon-box-soft">🔒</div>
                <h3>24/7 Security</h3>
                <p>CCTV surveillance and secure gates for your safety.</p>
            </article>

            <article class="feature-card">
                <div class="icon-box icon-box-soft">📶</div>
                <h3>High-Speed Wi-Fi</h3>
                <p>Fast internet connectivity ideal for work and studies.</p>
            </article>

            <article class="feature-card">
                <div class="icon-box icon-box-soft">📍</div>
                <h3>Prime Location</h3>
                <p>Minutes away from universities, malls, and transit hubs.</p>
            </article>

            <article class="feature-card">
                <div class="icon-box icon-box-soft">💧</div>
                <h3>Complete Utilities</h3>
                <p>Reliable water supply, submetered power, and maintenance.</p>
            </article>
        </div>
    </div>
</section>

<section class="location-section">
    <div class="section-container location-wrap">
        <div class="location-card">
            <div class="location-image">
                <img
                    src="assets/images/south-cotabato-map.svg"
                    alt="Offline map of South Cotabato showing Koronadal City and its ten municipalities"
                    width="1120"
                    height="760">
            </div>

            <div class="location-content">
                <span class="eyebrow eyebrow-dark">STRATEGIC POSITIONING</span>
                <h2>Elevated Living Across South Cotabato</h2>
                <p>Use this offline map to quickly find Koronadal City and each municipality in South Cotabato.</p>
                <a href="rooms.php" class="btn btn-primary btn-lg">View Available Rooms →</a>
                <a class="location-map-link" href="https://www.google.com/maps/search/?api=1&amp;query=South+Cotabato%2C+Philippines" target="_blank" rel="noopener noreferrer">Open interactive Google Map (internet required)</a>
            </div>
        </div>
    </div>
</section>

<section class="faq-section">
    <div class="section-container faq-container">
        <h2 class="section-title">FREQUENTLY ASKED QUESTIONS</h2>
        <p class="section-subtitle">Got questions about renting with us? Find quick answers here.</p>

        <div class="faq-list">
            <div class="faq-item open">
                <button class="faq-question" type="button">
                    What are the rental payment terms?
                    <span class="faq-icon">⌄</span>
                </button>
                <div class="faq-answer">
                    <p>Rent is due monthly and payment methods will be confirmed by the administrator upon move-in.</p>
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-question" type="button">
                    Are utilities included in the monthly rent?
                    <span class="faq-icon">⌄</span>
                </button>
                <div class="faq-answer">
                    <p>Water is included; electricity is submetered and billed separately based on usage.</p>
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-question" type="button">
                    How do I schedule a room viewing?
                    <span class="faq-icon">⌄</span>
                </button>
                <div class="faq-answer">
                    <p>Reach out through our Contact page and the administrator will coordinate a viewing schedule with you.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="/boarding_hauz/assets/js/script.js"></script>