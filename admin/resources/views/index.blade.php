<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI NeuraBot - Intelligent Chatbot Solutions</title>
    <meta name="theme-color" content="#00d2ff" />
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700&family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Three.js -->
    <script src="https://cdn.jsdelivr.net/npm/three@0.132.2/build/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.132.2/examples/js/controls/OrbitControls.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.132.2/examples/js/loaders/GLTFLoader.js"></script>
    <!-- GSAP for animations -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.11.4/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.11.4/ScrollTrigger.min.js"></script>
    <style>
        :root {
            --primary: #28334e;
            --secondary: #1d4ed8;
            --dark: #1b253b;
            --light: #ffffff;
            --gray: rgba(255, 255, 255, 0.7);
            --glass: rgba(20, 20, 60, 0.7);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--dark);
            color: var(--light);
            overflow-x: hidden;
        }

        /* Navigation */
        .nav-container {
            width: 80px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: var(--glass);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-right: 1px solid rgba(100, 150, 255, 0.1);
            padding: 30px 0;
            z-index: 100;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .logo {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.8rem;
            margin-bottom: 40px;
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            background: linear-gradient(var(--primary), var(--secondary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .nav-menu {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 15px;
            align-items: center;
        }

        .nav-item {
            position: relative;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--gray);
            font-size: 1.2rem;
        }

        .nav-item:hover {
            color: var(--light);
            background: rgba(100, 150, 255, 0.1);
            transform: translateY(-3px);
        }

        .nav-item.active {
            color: var(--light);
        }

        .nav-item.active::before {
            content: '}';
            position: absolute;
            left: -20px;
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 0.7; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.1); }
            100% { opacity: 0.7; transform: scale(1); }
        }

        /* Main Content */
        .main-content {
            margin-left: 80px;
            position: relative;
        }

        section {
            padding: 100px 10%;
            position: relative;
            overflow: hidden;
        }

        /* Hero Section */
        .hero {
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding-top: 150px;
        }

        .hero-content {
            max-width: 800px;
            z-index: 2;
        }

        .hero h1 {
            font-family: 'Orbitron', sans-serif;
            font-size: 4rem;
            margin-bottom: 20px;
            background: linear-gradient(90deg, var(--light), var(--primary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            line-height: 1.2;
        }

        .hero p {
            font-size: 1.2rem;
            line-height: 1.6;
            color: var(--gray);
            margin-bottom: 40px;
        }

        .button-group {
            display: flex;
            gap: 20px;
            justify-content: center;
        }

        .cta-button {
            display: inline-block;
            padding: 15px 30px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .primary-btn {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            color: var(--dark);
        }

        .primary-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 210, 255, 0.3);
        }

        .secondary-btn {
            background: transparent;
            color: var(--light);
            border: 1px solid var(--primary);
        }

        .secondary-btn:hover {
            background: rgba(0, 210, 255, 0.1);
            transform: translateY(-3px);
        }

        /* 3D Bot Container */
        .bot-container {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            opacity: 0.5;
        }

        /* Features Section */
        .features {
            background: rgba(10, 20, 40, 0.7);
        }

        .section-title {
            text-align: center;
            margin-bottom: 60px;
            font-family: 'Orbitron', sans-serif;
            font-size: 2.5rem;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 30px;
            transition: all 0.3s ease;
            border: 1px solid rgba(100, 150, 255, 0.1);
            backdrop-filter: blur(5px);
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 210, 255, 0.1);
            border-color: rgba(0, 210, 255, 0.3);
        }

        .feature-icon {
            font-size: 2.5rem;
            margin-bottom: 20px;
            color: var(--primary);
        }

        .feature-title {
            font-size: 1.5rem;
            margin-bottom: 15px;
            color: var(--light);
        }

        .feature-desc {
            color: var(--gray);
            line-height: 1.6;
        }

        /* Integration Section */
        .integrations {
            text-align: center;
        }

        .logos-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 40px;
            margin-top: 50px;
        }

        .logo-item {
            width: 120px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            transition: all 0.3s ease;
        }

        .logo-item:hover {
            transform: scale(1.1);
            background: rgba(0, 210, 255, 0.1);
        }

        .logo-item img {
            max-width: 80%;
            max-height: 80%;
            filter: grayscale(100%) brightness(2);
            transition: filter 0.3s ease;
        }

        .logo-item:hover img {
            filter: grayscale(0%) brightness(1);
        }

        /* Stats Section */
        .stats {
            background: linear-gradient(135deg, rgba(10, 30, 60, 0.8), rgba(20, 20, 60, 0.8));
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 40px;
            text-align: center;
        }

        .stat-item h3 {
            font-size: 3.5rem;
            font-family: 'Orbitron', sans-serif;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 10px;
        }

        .stat-item p {
            color: var(--gray);
            font-size: 1.1rem;
        }

        /* Testimonials */
        .testimonials .section-title {
            margin-bottom: 80px;
        }

        .testimonial-slider {
            max-width: 800px;
            margin: 0 auto;
            position: relative;
        }

        .testimonial {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 40px;
            margin: 20px;
            border: 1px solid rgba(100, 150, 255, 0.1);
            position: relative;
        }

        .testimonial:before {
            content: '"';
            position: absolute;
            top: 20px;
            left: 20px;
            font-size: 5rem;
            font-family: serif;
            color: rgba(0, 210, 255, 0.1);
            line-height: 1;
        }

        .testimonial-content {
            font-size: 1.1rem;
            line-height: 1.8;
            color: var(--gray);
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
        }

        .author-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            margin-right: 15px;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark);
            font-weight: bold;
        }

        .author-info h4 {
            color: var(--light);
            margin-bottom: 5px;
        }

        .author-info p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* CTA Section */
        .cta {
            text-align: center;
            background: linear-gradient(135deg, rgba(0, 210, 255, 0.1), rgba(58, 123, 213, 0.1));
        }

        .cta h2 {
            font-size: 2.5rem;
            margin-bottom: 30px;
            font-family: 'Orbitron', sans-serif;
        }

        .cta p {
            max-width: 700px;
            margin: 0 auto 40px;
            color: var(--gray);
            font-size: 1.1rem;
            line-height: 1.6;
        }

        /* Footer */
        footer {
            background: rgba(5, 10, 20, 0.9);
            padding: 60px 10% 30px;
            text-align: center;
        }

        .footer-logo {
            font-family: 'Orbitron', sans-serif;
            font-size: 2rem;
            margin-bottom: 30px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: inline-block;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 30px;
            margin-bottom: 40px;
        }

        .footer-links a {
            color: var(--gray);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 40px;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray);
            transition: all 0.3s ease;
        }

        .social-links a:hover {
            background: var(--primary);
            color: var(--dark);
            transform: translateY(-3px);
        }

        .copyright {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Background Elements */
        .bg-elements {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }

        .circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(100, 150, 255, 0.05);
            animation: float 15s infinite linear;
        }

        @keyframes float {
            0% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
            100% { transform: translateY(0) rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 992px) {
            .hero h1 {
                font-size: 3rem;
            }
            
            section {
                padding: 80px 5%;
            }
        }

        @media (max-width: 768px) {
            .nav-container {
                width: 60px;
            }
            
            .main-content {
                margin-left: 60px;
            }
            
            .hero h1 {
                font-size: 2.5rem;
            }
            
            .button-group {
                flex-direction: column;
                align-items: center;
            }
        }

        .pricing-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 30px;
            margin: 50px auto;
            max-width: 1200px;
        }

        .pricing-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(100, 150, 255, 0.1);
            border-radius: 15px;
            padding: 30px;
            width: 300px;
            text-align: center;
            transition: transform 0.3s;
        }

        .pricing-card:hover {
            transform: translateY(-10px);
        }

        .pricing-card h3 {
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .pricing-card p {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 20px;
        }

        .pricing-card .price {
            font-size: 2rem;
            font-family: 'Orbitron', sans-serif;
            margin-bottom: 20px;
        }

        .pricing-card button {
            background: linear-gradient(90deg, #00d2ff, #3a7bd5);
            color: #0a0a1a;
            font-weight: bold;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            cursor: pointer;
            transition: transform 0.3s;
        }

        .pricing-card button:hover {
            transform: translateY(-3px);
        }

        .contact-container {
            max-width: 800px;
            margin: 0 auto;
            background: rgba(20, 20, 60, 0.7);
            backdrop-filter: blur(10px);
            padding: 40px;
            border-radius: 15px;
        }

        .contact-container form {
            display: flex;
            flex-direction: column;
        }

        .contact-container input,
        .contact-container textarea {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(100, 150, 255, 0.1);
            margin: 10px 0;
            padding: 15px;
            border-radius: 8px;
            color: #fff;
            outline: none;
        }

        .contact-container button {
            background: linear-gradient(90deg, #00d2ff, #3a7bd5);
            color: #0a0a1a;
            font-weight: bold;
            border: none;
            padding: 12px 0;
            border-radius: 30px;
            cursor: pointer;
            margin-top: 15px;
            transition: transform 0.3s;
        }

        .contact-container button:hover {
            transform: translateY(-3px);
        }

    </style>
</head>
<body>
    <!-- Left Navigation -->
    <div class="nav-container">
        <div class="logo">NEURABOT</div>
        <ul class="nav-menu">
            <li class="nav-item active" data-section="hero">
                <i class="fas fa-home"></i>
            </li>
            <li class="nav-item" data-section="features">
                <i class="fas fa-cogs"></i>
            </li>
            <li class="nav-item" data-section="integrations">
                <i class="fas fa-plug"></i>
            </li>
            <li class="nav-item" data-section="stats">
                <i class="fas fa-chart-line"></i>
            </li>
            <li class="nav-item" data-section="cta">
                <i class="fas fa-envelope"></i>
            </li>
            <li class="nav-item" data-section="pricings">
                <i class="fas fa-dollar"></i>
            </li>
            <li class="nav-item" data-section="contact">
                <i class="fas fa-envelope"></i>
            </li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Background elements -->
        <div class="bg-elements" id="bg-elements"></div>
        {{-- <div class="bg-elements" id="ai-bot-canvas"></div> --}}
        
        <!-- Hero Section -->
        <section class="hero" id="hero">
            <div class="bot-container" id="ai-bot-canvas"></div>
            <div class="hero-content">
                <h1>Revolutionize Customer Experience with AI</h1>
                <p>NeuraLink AI seamlessly integrates with your existing systems to provide intelligent, context-aware responses to customer queries in real-time, 24/7.</p>
                <div class="button-group">
                    <a href="{{url('chat-bot/1')}}" class="cta-button primary-btn">Get Demo</a>
                    <a href="{{url('login')}}" class="cta-button secondary-btn">Lets get started</a>
                </div>
            </div>
        </section>
        
        <!-- Features Section -->
        <section class="features" id="features">
            <h2 class="section-title">Powerful Features</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-robot"></i>
                    </div>
                    <h3 class="feature-title">Natural Language Processing</h3>
                    <p class="feature-desc">Understands and responds to customer queries in human-like conversation with advanced NLP algorithms.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-database"></i>
                    </div>
                    <h3 class="feature-title">Real-time Data Access</h3>
                    <p class="feature-desc">Directly connects to your databases to provide accurate, up-to-date information to customers instantly.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-random"></i>
                    </div>
                    <h3 class="feature-title">Multi-platform Integration</h3>
                    <p class="feature-desc">Works across websites, mobile apps, social media, and messaging platforms with a single integration.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <h3 class="feature-title">Analytics Dashboard</h3>
                    <p class="feature-desc">Comprehensive insights into customer interactions, common queries, and satisfaction metrics.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h3 class="feature-title">Secure Authentication</h3>
                    <p class="feature-desc">Enterprise-grade security with optional customer verification for sensitive data access.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-language"></i>
                    </div>
                    <h3 class="feature-title">Multi-language Support</h3>
                    <p class="feature-desc">Communicates with customers in their preferred language with automatic translation.</p>
                </div>
            </div>
        </section>
        
        <!-- Integrations Section -->
        <section class="integrations" id="integrations">
            <h2 class="section-title">Seamless Integrations</h2>
            <p style="max-width: 700px; margin: 0 auto; color: var(--gray);">NeuraLink AI connects with all the tools you already use, with more added every week.</p>
            <div class="logos-grid">
                <div class="logo-item">
                    <i class="fab fa-shopify" style="font-size: 2.5rem;"></i>
                </div>
                <div class="logo-item">
                    <i class="fab fa-salesforce" style="font-size: 2.5rem;"></i>
                </div>
                <div class="logo-item">
                    <i class="fab fa-microsoft" style="font-size: 2.5rem;"></i>
                </div>
                <div class="logo-item">
                    <i class="fab fa-google" style="font-size: 2.5rem;"></i>
                </div>
                <div class="logo-item">
                    <i class="fab fa-wordpress" style="font-size: 2.5rem;"></i>
                </div>
                <div class="logo-item">
                    <i class="fab fa-slack" style="font-size: 2.5rem;"></i>
                </div>
            </div>
        </section>
        
        <!-- Stats Section -->
        <section class="stats" id="stats">
            <h2 class="section-title">By The Numbers</h2>
            <div class="stats-grid">
                <div class="stat-item">
                    <h3 class="counter" data-target="98">0</h3>
                    <p>Customer Satisfaction Rate</p>
                </div>
                <div class="stat-item">
                    <h3 class="counter" data-target="24">0</h3>
                    <p>Hour Response Availability</p>
                </div>
                <div class="stat-item">
                    <h3 class="counter" data-target="50">0</h3>
                    <p>Supported Languages</p>
                </div>
                <div class="stat-item">
                    <h3 class="counter" data-target="1000">0</h3>
                    <p>Happy Clients</p>
                </div>
            </div>
        </section>
        
        <!-- Testimonials Section -->
        <section class="testimonials" id="testimonials">
            <h2 class="section-title">What Our Clients Say</h2>
            <div class="testimonial-slider">
                <div class="testimonial">
                    <p class="testimonial-content">Implementing NeuraLink AI reduced our customer service response time by 80% while maintaining exceptional quality. Our customers love the instant, accurate responses they get at any time of day.</p>
                    <div class="testimonial-author">
                        <div class="author-avatar">JD</div>
                        <div class="author-info">
                            <h4>Jane Doe</h4>
                            <p>CTO, TechCorp</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pricings" id="pricings">
            <h2 class="section-title">Our Pricing Plans</h2>
            <div class="pricing-container">
                <div class="pricing-card">
                <h3>Basic</h3>
                <p>For individuals starting out.</p>
                <div class="price">$19/mo</div>
                <button>Choose Plan</button>
                </div>
                <div class="pricing-card">
                <h3>Pro</h3>
                <p>For growing teams and businesses.</p>
                <div class="price">$49/mo</div>
                <button>Choose Plan</button>
                </div>
                <div class="pricing-card">
                <h3>Enterprise</h3>
                <p>Custom solutions for large organizations.</p>
                <div class="price">Contact Us</div>
                <button>Contact Sales</button>
                </div>
            </div>
        </section>

        <section class="contact" id="contact">
            <h2 class="section-title">Contact</h1>
            <div class="contact-container">
                <form>
                    <input type="text" placeholder="Your Name" required />
                    <input type="email" placeholder="Your Email" required />
                    <textarea rows="6" placeholder="Your Message" required></textarea>
                    <button type="submit">Send Message</button>
                </form>
            </div>
        </section>
        <!-- CTA Section -->
        <section class="cta" id="cta">
            <h2>Ready to Transform Your Customer Experience?</h2>
            <p>Schedule a demo today and see how NeuraLink AI can revolutionize your customer interactions and streamline your operations.</p>
            <a href="#" class="cta-button primary-btn">Get Started Now</a>
        </section>
        
        <!-- Footer -->
        <footer>
            <div class="footer-logo">NEURALINK AI</div>
            <div class="footer-links">
                <a href="#">Dashboard</a>
                <a href="#features">Features</a>
                <a href="#integrations">Integrations</a>
                <a href="#">Pricing</a>
                <a href="#">Documentation</a>
                <a href="#">Contact</a>
            </div>
            <div class="social-links">
                <a href="#"><i class="fab fa-twitter"></i></a>
                <a href="#"><i class="fab fa-linkedin"></i></a>
                <a href="#"><i class="fab fa-github"></i></a>
                <a href="#"><i class="fab fa-youtube"></i></a>
            </div>
            <p class="copyright">© 2023 NeuraLink AI. All rights reserved.</p>
        </footer>
    </div>

    <script>
        // Background elements
        const bgContainer = document.getElementById('ai-bot-canvas');
        for (let i = 0; i < 15; i++) {
            const circle = document.createElement('div');
            circle.classList.add('circle');
            
            const size = Math.random() * 300 + 100;
            const posX = Math.random() * 100;
            const posY = Math.random() * 100;
            const delay = Math.random() * 15;
            
            circle.style.width = `${size}px`;
            circle.style.height = `${size}px`;
            circle.style.left = `${posX}%`;
            circle.style.top = `${posY}%`;
            circle.style.animationDelay = `${delay}s`;
            
            bgContainer.appendChild(circle);
        }

        // Navigation animation
        const navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(item => {
            item.addEventListener('click', () => {
                const sectionId = item.getAttribute('data-section');
                const section = document.getElementById(sectionId);
                
                navItems.forEach(i => i.classList.remove('active'));
                item.classList.add('active');
                
                window.scrollTo({
                    top: section.offsetTop,
                    behavior: 'smooth'
                });
            });
        });


        function initBotScene() {
            const canvas = document.getElementById('ai-bot-canvas');
            const scene = new THREE.Scene();
            const camera = new THREE.PerspectiveCamera(75, canvas.offsetWidth / canvas.offsetHeight, 0.1, 1000);
            const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
            
            renderer.setSize(canvas.offsetWidth, canvas.offsetHeight);
            canvas.appendChild(renderer.domElement);
            
            // Add lights
            const ambientLight = new THREE.AmbientLight(0x404040);
            scene.add(ambientLight);
            
            const directionalLight = new THREE.DirectionalLight(0xffffff, 1);
            directionalLight.position.set(1, 1, 1);
            scene.add(directionalLight);
            
            // Create AI bot model (simplified for demo)
            const geometry = new THREE.SphereGeometry(1, 32, 32);
            const material = new THREE.MeshPhongMaterial({ 
                color: 0x6C63FF,
                emissive: 0x00D1FF,
                emissiveIntensity: 0.3,
                specular: 0xffffff,
                shininess: 50,
                transparent: true,
                opacity: 0.9
            });
            
            const bot = new THREE.Mesh(geometry, material);
            scene.add(bot);
            
            // Add floating particles
            const particles = new THREE.Group();
            scene.add(particles);
            
            for (let i = 0; i < 100; i++) {
                const particleGeometry = new THREE.SphereGeometry(0.05, 8, 8);
                const particleMaterial = new THREE.MeshBasicMaterial({ 
                    color: 0x00D1FF,
                    transparent: true,
                    opacity: 0.7
                });
                const particle = new THREE.Mesh(particleGeometry, particleMaterial);
                
                particle.position.x = (Math.random() - 0.5) * 10;
                particle.position.y = (Math.random() - 0.5) * 10;
                particle.position.z = (Math.random() - 0.5) * 10;
                
                particle.userData = {
                    speed: Math.random() * 0.02 + 0.01,
                    angle: Math.random() * Math.PI * 2
                };
                
                particles.add(particle);
            }
            
            camera.position.z = 5;
            
            // Animation loop
            function animate() {
                requestAnimationFrame(animate);
                
                bot.rotation.x += 0.005;
                bot.rotation.y += 0.01;
                
                particles.children.forEach(particle => {
                    particle.userData.angle += particle.userData.speed;
                    particle.position.x = Math.cos(particle.userData.angle) * 3;
                    particle.position.z = Math.sin(particle.userData.angle) * 3;
                });
                
                renderer.render(scene, camera);
            }
            
            animate();
            
            // Handle resize
            window.addEventListener('resize', () => {
                camera.aspect = canvas.offsetWidth / canvas.offsetHeight;
                camera.updateProjectionMatrix();
                renderer.setSize(canvas.offsetWidth, canvas.offsetHeight);
            });
        }
        
        function initDemoScene() {
            const canvas = document.getElementById('demo-canvas');
            const scene = new THREE.Scene();
            const camera = new THREE.PerspectiveCamera(75, canvas.offsetWidth / canvas.offsetHeight, 0.1, 1000);
            const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
            
            renderer.setSize(canvas.offsetWidth, canvas.offsetHeight);
            canvas.appendChild(renderer.domElement);
            
            // Add lights
            const ambientLight = new THREE.AmbientLight(0x404040);
            scene.add(ambientLight);
            
            const directionalLight = new THREE.DirectionalLight(0xffffff, 0.8);
            directionalLight.position.set(1, 1, 1);
            scene.add(directionalLight);
            
            // Create data nodes
            const nodes = new THREE.Group();
            scene.add(nodes);
            
            for (let i = 0; i < 10; i++) {
                const size = Math.random() * 0.3 + 0.2;
                const geometry = new THREE.IcosahedronGeometry(size, 1);
                const material = new THREE.MeshPhongMaterial({ 
                    color: i % 2 === 0 ? 0x6C63FF : 0x00D1FF,
                    emissive: i % 2 === 0 ? 0x6C63FF : 0x00D1FF,
                    emissiveIntensity: 0.2,
                    transparent: true,
                    opacity: 0.8
                });
                const node = new THREE.Mesh(geometry, material);
                
                node.position.x = (Math.random() - 0.5) * 5;
                node.position.y = (Math.random() - 0.5) * 5;
                node.position.z = (Math.random() - 0.5) * 5;
                
                node.userData = {
                    speed: Math.random() * 0.02 + 0.01,
                    direction: new THREE.Vector3(
                        Math.random() - 0.5,
                        Math.random() - 0.5,
                        Math.random() - 0.5
                    ).normalize()
                };
                
                nodes.add(node);
            }
            
            // Create connections
            const lines = new THREE.Group();
            scene.add(lines);
            
            function updateConnections() {
                // Clear existing lines
                while(lines.children.length) {
                    lines.remove(lines.children[0]);
                }
                
                // Create new connections
                nodes.children.forEach((node1, i) => {
                    nodes.children.slice(i + 1).forEach(node2 => {
                        const distance = node1.position.distanceTo(node2.position);
                        if (distance < 3) {
                            const geometry = new THREE.BufferGeometry().setFromPoints([
                                new THREE.Vector3(node1.position.x, node1.position.y, node1.position.z),
                                new THREE.Vector3(node2.position.x, node2.position.y, node2.position.z)
                            ]);
                            const material = new THREE.LineBasicMaterial({ 
                                color: 0x6C63FF,
                                transparent: true,
                                opacity: 0.5 - (distance / 6)
                            });
                            const line = new THREE.Line(geometry, material);
                            lines.add(line);
                        }
                    });
                });
            }
            
            camera.position.z = 8;
            
            // Animation loop
            function animate() {
                requestAnimationFrame(animate);
                
                nodes.children.forEach(node => {
                    node.position.addScaledVector(node.userData.direction, node.userData.speed);
                    
                    // Bounce off invisible walls
                    if (Math.abs(node.position.x) > 2.5) node.userData.direction.x *= -1;
                    if (Math.abs(node.position.y) > 2.5) node.userData.direction.y *= -1;
                    if (Math.abs(node.position.z) > 2.5) node.userData.direction.z *= -1;
                    
                    // Rotate
                    node.rotation.x += 0.01;
                    node.rotation.y += 0.01;
                });
                
                updateConnections();
                
                renderer.render(scene, camera);
            }
            
            animate();
            
            // Handle resize
            window.addEventListener('resize', () => {
                camera.aspect = canvas.offsetWidth / canvas.offsetHeight;
                camera.updateProjectionMatrix();
                renderer.setSize(canvas.offsetWidth, canvas.offsetHeight);
            });
        }
        

        // 3D Bot Implementation
        let scene, camera, renderer, bot;
        
        function init3DBot() {
            // Scene setup
            scene = new THREE.Scene();
            scene.background = null;
            
            // Camera
            camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
            camera.position.z = 5;
            
            // Renderer
            renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
            renderer.setSize(document.getElementById('bot-container').clientWidth, document.getElementById('bot-container').clientHeight);
            document.getElementById('bot-container').appendChild(renderer.domElement);
            
            // Lights
            const ambientLight = new THREE.AmbientLight(0x404040);
            scene.add(ambientLight);
            
            const directionalLight = new THREE.DirectionalLight(0xffffff, 1);
            directionalLight.position.set(1, 1, 1);
            scene.add(directionalLight);
            
            // Point lights for glowing effect
            const pointLight1 = new THREE.PointLight(0x00a8ff, 1, 10);
            pointLight1.position.set(1, 1, 2);
            scene.add(pointLight1);
            
            const pointLight2 = new THREE.PointLight(0x3a7bd5, 1, 10);
            pointLight2.position.set(-1, -1, 2);
            scene.add(pointLight2);
            
            // Bot model
            createBotModel();
            
            // Animation
            function animate() {
                requestAnimationFrame(animate);
                
                // Gentle floating animation
                if (bot) {
                    bot.rotation.y += 0.005;
                    bot.position.y = Math.sin(Date.now() * 0.001) * 0.1;
                }
                
                renderer.render(scene, camera);
            }
            
            animate();
            
            // Handle window resize
            window.addEventListener('resize', () => {
                camera.aspect = document.getElementById('bot-container').clientWidth / document.getElementById('bot-container').clientHeight;
                camera.updateProjectionMatrix();
                renderer.setSize(document.getElementById('bot-container').clientWidth, document.getElementById('bot-container').clientHeight);
            });
        }
        
        function createBotModel() {
            // Main body
            const bodyGeometry = new THREE.CylinderGeometry(0.5, 0.5, 1.5, 32);
            const bodyMaterial = new THREE.MeshPhongMaterial({ 
                color: 0x00a8ff,
                emissive: 0x003366,
                emissiveIntensity: 0.5,
                specular: 0x555555,
                shininess: 30,
                transparent: true,
                opacity: 0.9
            });
            
            const body = new THREE.Mesh(bodyGeometry, bodyMaterial);
            body.position.y = -0.2;
            scene.add(body);
            
            // Head
            const headGeometry = new THREE.SphereGeometry(0.6, 32, 32);
            const headMaterial = new THREE.MeshPhongMaterial({ 
                color: 0xffffff,
                transparent: true,
                opacity: 0.9
            });
            
            const head = new THREE.Mesh(headGeometry, headMaterial);
            head.position.y = 0.8;
            body.add(head);
            
            // Eyes
            const eyeGeometry = new THREE.SphereGeometry(0.15, 16, 16);
            const eyeMaterial = new THREE.MeshPhongMaterial({ 
                color: 0x00ffff,
                emissive: 0x00ffff,
                emissiveIntensity: 0.3
            });
            
            const leftEye = new THREE.Mesh(eyeGeometry, eyeMaterial);
            leftEye.position.set(-0.2, 0.9, 0.5);
            head.add(leftEye);
            
            const rightEye = new THREE.Mesh(eyeGeometry, eyeMaterial);
            rightEye.position.set(0.2, 0.9, 0.5);
            head.add(rightEye);
            
            // Antenna
            const antennaGeometry = new THREE.CylinderGeometry(0.03, 0.03, 0.5, 8);
            const antennaMaterial = new THREE.MeshPhongMaterial({ color: 0x00a8ff });
            
            const antenna = new THREE.Mesh(antennaGeometry, antennaMaterial);
            antenna.position.set(0, 1.3, 0);
            antenna.rotation.x = Math.PI / 4;
            head.add(antenna);
            
            // Antenna ball
            const antennaBallGeometry = new THREE.SphereGeometry(0.08, 16, 16);
            const antennaBallMaterial = new THREE.MeshPhongMaterial({ 
                color: 0xff00ff,
                emissive: 0xff00ff,
                emissiveIntensity: 0.5
            });
            
            const antennaBall = new THREE.Mesh(antennaBallGeometry, antennaBallMaterial);
            antennaBall.position.set(0, 1.55, -0.15);
            head.add(antennaBall);
            
            // Arms
            const armGeometry = new THREE.CylinderGeometry(0.07, 0.07, 0.8, 8);
            const armMaterial = new THREE.MeshPhongMaterial({ color: 0x00a8ff });
            
            const leftArm = new THREE.Mesh(armGeometry, armMaterial);
            leftArm.position.set(-0.6, 0, 0);
            leftArm.rotation.z = Math.PI / 2;
            body.add(leftArm);
            
            const rightArm = new THREE.Mesh(armGeometry, armMaterial);
            rightArm.position.set(0.6, 0, 0);
            rightArm.rotation.z = Math.PI / 2;
            body.add(rightArm);
            
            // Hands
            const handGeometry = new THREE.SphereGeometry(0.1, 16, 16);
            const handMaterial = new THREE.MeshPhongMaterial({ color: 0xffffff });
            
            const leftHand = new THREE.Mesh(handGeometry, handMaterial);
            leftHand.position.set(-1, 0, 0);
            body.add(leftHand);
            
            const rightHand = new THREE.Mesh(handGeometry, handMaterial);
            rightHand.position.set(1, 0, 0);
            body.add(rightHand);
            
            bot = body;
        }
        
        // Counter animation
        function animateCounters() {
            const counters = document.querySelectorAll('.counter');
            const speed = 200;
            
            counters.forEach(counter => {
                const target = +counter.getAttribute('data-target');
                const count = +counter.innerText;
                const increment = target / speed;
                
                if (count < target) {
                    counter.innerText = Math.ceil(count + increment);
                    setTimeout(animateCounters, 1);
                } else {
                    counter.innerText = target;
                }
            });
        }
        
        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            //init3DBot();
            initBotScene();
            //initDemoScene();
            // GSAP animations
            gsap.registerPlugin(ScrollTrigger);
            
            // Animate elements on scroll
            gsap.utils.toArray('.feature-card').forEach((card, i) => {
                gsap.from(card, {
                    scrollTrigger: {
                        trigger: card,
                        start: "top 80%",
                        toggleActions: "play none none none"
                    },
                    y: 50,
                    opacity: 0,
                    duration: 0.8,
                    delay: i * 0.1
                });
            });
            
            gsap.utils.toArray('.logo-item').forEach((item, i) => {
                gsap.from(item, {
                    scrollTrigger: {
                        trigger: item,
                        start: "top 80%",
                        toggleActions: "play none none none"
                    },
                    y: 30,
                    opacity: 0,
                    duration: 0.6,
                    delay: i * 0.1
                });
            });
            
            gsap.utils.toArray('.stat-item').forEach((item, i) => {
                gsap.from(item, {
                    scrollTrigger: {
                        trigger: item,
                        start: "top 80%",
                        toggleActions: "play none none none",
                        onEnter: animateCounters
                    },
                    y: 30,
                    opacity: 0,
                    duration: 0.6,
                    delay: i * 0.1
                });
            });
            
            gsap.from('.testimonial', {
                scrollTrigger: {
                    trigger: '.testimonial',
                    start: "top 80%",
                    toggleActions: "play none none none"
                },
                y: 50,
                opacity: 0,
                duration: 1
            });
            
            // Initial animations
            gsap.from('.nav-container', { 
                duration: 1, 
                x: -50, 
                opacity: 0, 
                ease: 'power3.out' 
            });
            
            gsap.from('.hero-content h1', { 
                duration: 1.5, 
                y: 50, 
                opacity: 0, 
                delay: 0.3, 
                ease: 'back.out' 
            });
            
            gsap.from('.hero-content p', { 
                duration: 1.5, 
                y: 50, 
                opacity: 0, 
                delay: 0.5, 
                ease: 'back.out' 
            });
            
            gsap.from('.button-group', { 
                duration: 1.5, 
                y: 50, 
                opacity: 0, 
                delay: 0.7, 
                ease: 'back.out' 
            });
        });
    </script>
    <!-- The embed code — uses the Acme project's API key for testing -->
<script src="/AI-CRM-AGENT/admin/public/widget/loader.js?v=3"
        data-project-key="ec60c1a7e8ef712b53e5d04b9fba7d3b5bf1b6beb5b9a4a6"></script>

</body>
</html>