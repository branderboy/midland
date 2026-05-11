(function () {
    'use strict';

    var lightbox = null;
    var imgEl = null;
    var captionEl = null;
    var counterEl = null;
    var currentGallery = null;
    var currentIndex = 0;
    var lastFocus = null;

    function buildLightbox() {
        if (lightbox) return;

        lightbox = document.createElement('div');
        lightbox.className = 'mcg-lightbox';
        lightbox.setAttribute('role', 'dialog');
        lightbox.setAttribute('aria-modal', 'true');
        lightbox.setAttribute('aria-label', 'Image viewer');
        lightbox.innerHTML =
            '<button type="button" class="mcg-lightbox-btn mcg-lightbox-close" aria-label="Close">×</button>'
            + '<button type="button" class="mcg-lightbox-btn mcg-lightbox-prev" aria-label="Previous image">‹</button>'
            + '<button type="button" class="mcg-lightbox-btn mcg-lightbox-next" aria-label="Next image">›</button>'
            + '<div class="mcg-lightbox-counter" aria-live="polite"></div>'
            + '<div class="mcg-lightbox-img-wrap">'
            +   '<div class="mcg-lightbox-spinner"></div>'
            +   '<img class="mcg-lightbox-img" alt="" />'
            + '</div>'
            + '<div class="mcg-lightbox-caption"></div>';
        document.body.appendChild(lightbox);

        imgEl     = lightbox.querySelector('.mcg-lightbox-img');
        captionEl = lightbox.querySelector('.mcg-lightbox-caption');
        counterEl = lightbox.querySelector('.mcg-lightbox-counter');

        lightbox.querySelector('.mcg-lightbox-close').addEventListener('click', close);
        lightbox.querySelector('.mcg-lightbox-prev').addEventListener('click', function (e) { e.stopPropagation(); step(-1); });
        lightbox.querySelector('.mcg-lightbox-next').addEventListener('click', function (e) { e.stopPropagation(); step(1); });
        lightbox.addEventListener('click', function (e) {
            if (e.target === lightbox || e.target === imgEl.parentNode) close();
        });
        imgEl.addEventListener('load', function () {
            lightbox.classList.remove('is-loading');
        });

        document.addEventListener('keydown', function (e) {
            if (!lightbox.classList.contains('is-open')) return;
            if (e.key === 'Escape')      close();
            else if (e.key === 'ArrowLeft')  step(-1);
            else if (e.key === 'ArrowRight') step(1);
        });
    }

    function open(gallery, index) {
        buildLightbox();
        currentGallery = gallery;
        currentIndex = index;
        lastFocus = document.activeElement;
        document.body.style.overflow = 'hidden';
        lightbox.classList.add('is-open');
        show(index);
        lightbox.querySelector('.mcg-lightbox-close').focus();
    }

    function close() {
        if (!lightbox) return;
        lightbox.classList.remove('is-open');
        document.body.style.overflow = '';
        imgEl.src = '';
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
        currentGallery = null;
    }

    function step(dir) {
        if (!currentGallery) return;
        var items = getItems(currentGallery);
        if (!items.length) return;
        currentIndex = (currentIndex + dir + items.length) % items.length;
        show(currentIndex);
    }

    function show(index) {
        var items = getItems(currentGallery);
        var btn = items[index];
        if (!btn) return;
        lightbox.classList.add('is-loading');
        imgEl.src = btn.getAttribute('data-full') || '';
        imgEl.alt = btn.getAttribute('data-caption') || '';
        captionEl.textContent = btn.getAttribute('data-caption') || '';
        counterEl.textContent = (index + 1) + ' / ' + items.length;
    }

    function getItems(gallery) {
        return Array.prototype.slice.call(gallery.querySelectorAll('.mcg-item'));
    }

    function init() {
        var galleries = document.querySelectorAll('.mcg-gallery');
        Array.prototype.forEach.call(galleries, function (gallery) {
            if (gallery.dataset.mcgBound) return;
            gallery.dataset.mcgBound = '1';
            var items = getItems(gallery);
            items.forEach(function (btn, i) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    open(gallery, i);
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
