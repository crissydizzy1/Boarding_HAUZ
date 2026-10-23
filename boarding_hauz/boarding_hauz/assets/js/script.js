// ============ Available Rooms Page — Filtering ============
// Only run this code if we're on a page that actually has the filter bar
const applyFiltersBtn = document.getElementById('applyFiltersBtn');

if (applyFiltersBtn) {
    const searchInput = document.getElementById('searchInput');
    const capacityFilter = document.getElementById('capacityFilter');
    const resultsCount = document.getElementById('roomsCount');
    const noResultsMsg = document.getElementById('noResultsMsg');

    const applyAvailableRoomFilters = function () {
        const searchValue = searchInput.value.trim().toLowerCase();
        const capacityValue = capacityFilter.value;
        const priceValue = document.getElementById('priceFilter').value;
        const statusValue = document.getElementById('statusFilter').value;

        const cards = document.querySelectorAll('.public-room-card');
        let visibleCount = 0;

        cards.forEach(function (card) {
            const cardSearch = card.dataset.search;
            const cardStatus = card.dataset.status;
            const cardCapacity = Number(card.dataset.capacity);
            const cardPrice = Number(card.dataset.price);

            let matchesSearch = cardSearch.includes(searchValue);
            let matchesCapacity = capacityValue === 'all'
                || cardCapacity === Number(capacityValue);
            let matchesStatus = statusValue === 'all'
                || (statusValue === 'available' && ['available', 'partially occupied'].includes(cardStatus))
                || cardStatus.replace(/ /g, '-') === statusValue;

            let matchesPrice = true;
            if (priceValue === 'low') matchesPrice = cardPrice < 2500;
            if (priceValue === 'mid') matchesPrice = cardPrice >= 2500 && cardPrice <= 4000;
            if (priceValue === 'high') matchesPrice = cardPrice > 4000;

            if (matchesSearch && matchesCapacity && matchesStatus && matchesPrice) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        if (noResultsMsg) {
            noResultsMsg.style.display = visibleCount === 0 ? 'block' : 'none';
        }
        if (resultsCount) {
            resultsCount.textContent = 'Showing ' + visibleCount + (visibleCount === 1 ? ' room' : ' rooms');
        }
    };

    applyFiltersBtn.addEventListener('click', applyAvailableRoomFilters);
    searchInput.addEventListener('input', applyAvailableRoomFilters);
    capacityFilter.addEventListener('change', applyAvailableRoomFilters);
    document.getElementById('priceFilter').addEventListener('change', applyAvailableRoomFilters);
    document.getElementById('statusFilter').addEventListener('change', applyAvailableRoomFilters);
}

// ============ About Page — FAQ Accordion ============
const faqItems = document.querySelectorAll('.faq-item');

faqItems.forEach(function (item) {
    const question = item.querySelector('.faq-question');
    question.addEventListener('click', function () {
        item.classList.toggle('open');
    });


});
