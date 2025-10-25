(function () {
    const updateTrackAnimation = (track) => {
        const speed = parseFloat(track.dataset.marqueeSpeed) || 30;
        if (speed <= 0) {
            return;
        }

        const container = track.parentElement;
        if (!container) {
            return;
        }

        const distance = track.scrollWidth + container.offsetWidth;
        const duration = distance / speed;
        track.style.animation = `marquee-scroll ${duration}s linear infinite`;
    };

    const refreshAllTracks = () => {
        const tracks = document.querySelectorAll('[data-marquee-speed]');
        tracks.forEach((track) => {
            track.style.animation = 'none';
            window.requestAnimationFrame(() => updateTrackAnimation(track));
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshAllTracks);
    } else {
        refreshAllTracks();
    }

    window.addEventListener('load', refreshAllTracks);
    window.addEventListener('resize', refreshAllTracks);
})();
