// tvaibwc-iframe.js
(function() {
    // Create iframe
    const iframe = document.createElement('iframe');
    iframe.src = 'http://localhost/WebBot/tvaibwc-widget.html';
    iframe.style.border = 'none';
    iframe.style.position = 'fixed';
    iframe.style.bottom = '20px';
    iframe.style.right = '20px';
    iframe.style.width = '370px';
    iframe.style.height = '550px';
    iframe.style.zIndex = '999999';
    iframe.style.display = 'none';
    iframe.id = 'tvaibwc-iframe';
    
    // Create toggle button
    const toggleBtn = document.createElement('button');
    toggleBtn.innerHTML = '<i class="fas fa-comment"></i>';
    toggleBtn.style.position = 'fixed';
    toggleBtn.style.bottom = '20px';
    toggleBtn.style.right = '20px';
    toggleBtn.style.width = '60px';
    toggleBtn.style.height = '60px';
    toggleBtn.style.borderRadius = '50%';
    toggleBtn.style.backgroundColor = '#1a365d';
    toggleBtn.style.color = 'white';
    toggleBtn.style.border = 'none';
    toggleBtn.style.cursor = 'pointer';
    toggleBtn.style.zIndex = '999999';
    toggleBtn.id = 'tvaibwc-toggle';
    
    // Add elements to page
    document.body.appendChild(iframe);
    document.body.appendChild(toggleBtn);
    
    // Add Font Awesome (if not already loaded)
    if (!document.querySelector('link[href*="font-awesome"]')) {
        const faLink = document.createElement('link');
        faLink.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
        faLink.rel = 'stylesheet';
        document.head.appendChild(faLink);
    }
    
    // Toggle functionality
    toggleBtn.addEventListener('click', function() {
        const iframe = document.getElementById('tvaibwc-iframe');
        if (iframe.style.display === 'none') {
            iframe.style.display = 'block';
            toggleBtn.innerHTML = '<i class="fas fa-chevron-down"></i>';
        } else {
            iframe.style.display = 'none';
            toggleBtn.innerHTML = '<i class="fas fa-comment"></i>';
        }
    });
})();