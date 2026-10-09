document.addEventListener('DOMContentLoaded', () => {
    
    // ==========================================
    // 1. TAB SWITCHING FUNCTIONALITY
    // ==========================================
    const tabs = document.querySelectorAll('.tab');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remove the 'active' class from ALL tabs
            tabs.forEach(t => t.classList.remove('active'));
            
            // Add the 'active' class to the specific tab that was clicked
            this.classList.add('active');
            
            console.log(`Filter changed to: ${this.textContent.trim()}`);
        });
    });

    // ==========================================
    // 2. SEARCH BAR FUNCTIONALITY (Enter Key & Icon Click)
    // ==========================================
    const searchBar = document.querySelector('.search-bar');
    const searchInput = document.getElementById('searchInput');
    
    if (searchInput && searchBar) {
        
        // Function to handle the search action
        const handleSearch = () => {
            const query = searchInput.value.trim();
            
            if (query) {
                // Since there's no backend, log the search and simulate a search action
                console.log(`🔍 Search triggered for: "${query}"`);
                console.log(`Searching by: Room Number, Building Name, or Landlord...`);
                
                // Trigger the minimalist pulse animation on the search bar
                searchBar.classList.add('searching');
                
                // Remove the animation class after 400ms so it can be triggered again
                setTimeout(() => {
                    searchBar.classList.remove('searching');
                }, 400);
                
                // Clear the input to simulate a search being processed
                searchInput.value = '';
            }
        };

        // Listen for the 'Enter' key on the input
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault(); // Prevent default form submission
                handleSearch();
            }
        });

        // Search when the user clicks the search icon
        const searchIcon = searchBar.querySelector('i');
        if (searchIcon) {
            searchIcon.addEventListener('click', handleSearch);
        }
    }
});