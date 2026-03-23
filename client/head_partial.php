<?php
// client/head_partial.php
if (!isset($pageTitle)) $pageTitle = APP_NAME;
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Fonts - Inter sebagai font utama -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Icons - Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --pure-black: #111827;
            --pure-white: #ffffff;
            --soft-gray: #F8F9FA;
            --border-subtle: #f0f0f0;
            --text-muted: #6B7280;
            --sidebar-width: 260px;
            --header-height: 70px;
            
            /* Dark mode variables */
            --bg-primary: #ffffff;
            --bg-secondary: #F8F9FA;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --border-color: #f0f0f0;
        }

        [data-theme="dark"] {
            --pure-black: #ffffff;
            --pure-white: #1F2937;
            --soft-gray: #111827;
            --border-subtle: #374151;
            --text-muted: #9CA3AF;
            
            --bg-primary: #1F2937;
            --bg-secondary: #111827;
            --text-primary: #F8F9FA;
            --text-secondary: #9CA3AF;
            --border-color: #374151;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--soft-gray);
            color: var(--pure-black);
            min-height: 100vh;
            line-height: 1.5;
            overflow-x: hidden;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

      

       #dashboardContent { 
    display: block;
    min-height: 100vh;
}

        /* Layout */
        .dashboard-layout {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
            z-index: 40;
            overflow-y: auto;
            transition: transform 0.3s ease, background-color 0.3s ease;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            min-width: 0;
            min-height: 100vh;
            background-color: var(--soft-gray);
            transition: margin-left 0.3s ease, background-color 0.3s ease;
        }

     .main-header {
    position: sticky;
    top: 0;
    width: 100%;
    background-color: var(--bg-primary);
    border-bottom: 1px solid var(--border-color);
    z-index: 30;
    margin: 0;
    padding: 0;
}

.main-header > div {
    max-width: 1420px;
    margin: 0 auto;
    padding: 0 2rem;
}

        /* Content wrapper */
        .content-shell {
            max-width: 1420px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        /* Sidebar Overlay untuk mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 35;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-overlay.active {
            opacity: 1;
        }

        .sidebar-overlay:not(.hidden) {
            display: block;
        }

        /* Mobile Styles */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .sidebar-overlay {
                display: block;
            }
            
            .sidebar-overlay.hidden {
                display: none;
            }
            
            .content-shell {
                padding: 1rem;
            }
        }

        /* Utility classes */
        .text-center { text-align: center; }
        .mt-1 { margin-top: 0.5rem; }
        .mt-2 { margin-top: 1rem; }
        .mt-3 { margin-top: 1.5rem; }
        .mb-1 { margin-bottom: 0.5rem; }
        .mb-2 { margin-bottom: 1rem; }
        .mb-3 { margin-bottom: 1.5rem; }
        .p-2 { padding: 1rem; }
        .p-3 { padding: 1.5rem; }
        
        .flex { display: flex; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-2 { gap: 0.75rem; }
        .gap-3 { gap: 1rem; }

        /* Scrollbar khusus untuk navigasi soal */
.question-grid::-webkit-scrollbar {
    width: 4px; /* Lebar scrollbar yang tipis */
}

.question-grid::-webkit-scrollbar-track {
    background: var(--border-color);
    border-radius: 10px;
}

.question-grid::-webkit-scrollbar-thumb {
    background: var(--text-secondary);
    border-radius: 10px;
}

.question-grid::-webkit-scrollbar-thumb:hover {
    background: var(--text-primary);
}

/* Scrollbar untuk sidebar */
.sidebar::-webkit-scrollbar {
    width: 4px;
}

.sidebar::-webkit-scrollbar-track {
    background: var(--border-color);
    border-radius: 10px;
}

.sidebar::-webkit-scrollbar-thumb {
    background: var(--text-secondary);
    border-radius: 10px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: var(--text-primary);
}
    </style>
</head>
<body>
   

   <script>
// Theme toggle function
window.toggleTheme = function() {
    var html = document.documentElement;
    var currentTheme = html.getAttribute('data-theme');
    var newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
};

// Load saved theme
(function() {
    var savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
})();
</script>