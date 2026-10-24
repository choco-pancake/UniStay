document.addEventListener('DOMContentLoaded', () => {
    const currentPage = window.location.pathname.split('/').pop();
    document.querySelectorAll('.nav-item[href]').forEach((item) => {
        item.classList.toggle('active', item.getAttribute('href') === currentPage);
    });
});