<?php
// Unified split-screen auth container for CHRMO
// Does not change server-side logic of log-in.php or register.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CHRMO Authentication</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Poppins','sans-serif'] },
          colors: { primary: { 400: '#38bdf8', 500: '#0ea5e9' } },
          keyframes: {
            float: {
              '0%': { transform: 'translateY(0) translateX(0) rotate(0deg)', opacity: 0.6 },
              '50%': { transform: 'translateY(-10px) translateX(6px) rotate(1deg)', opacity: 0.9 },
              '100%': { transform: 'translateY(0) translateX(0) rotate(0deg)', opacity: 0.6 },
            },
            drift: {
              '0%': { transform: 'translateX(0)' },
              '50%': { transform: 'translateX(12px)' },
              '100%': { transform: 'translateX(0)' },
            },
            slideIn: {
              '0%': { transform: 'translateX(30px)', opacity: 0 },
              '100%': { transform: 'translateX(0)', opacity: 1 },
            },
            slideLeft: {
              '0%': { transform: 'translateX(24px)', opacity: 0 },
              '100%': { transform: 'translateX(0)', opacity: 1 },
            },
            slideRight: {
              '0%': { transform: 'translateX(-24px)', opacity: 0 },
              '100%': { transform: 'translateX(0)', opacity: 1 },
            },
            rotateSlow: {
              '0%': { transform: 'rotate(0deg)' },
              '50%': { transform: 'rotate(2.5deg)' },
              '100%': { transform: 'rotate(0deg)' },
            },
            shake: {
              '0%,100%': { transform: 'translateX(0)' },
              '20%': { transform: 'translateX(-6px)' },
              '40%': { transform: 'translateX(6px)' },
              '60%': { transform: 'translateX(-4px)' },
              '80%': { transform: 'translateX(4px)' },
            }
          },
          animation: {
            float: 'float 22s ease-in-out infinite',
            floatSlow: 'float 28s ease-in-out infinite',
            drift: 'drift 24s ease-in-out infinite',
            slideIn: 'slideIn .35s ease-out both',
            slideLeft: 'slideLeft .32s ease-out both',
            slideRight: 'slideRight .32s ease-out both',
            rotateSlow: 'rotateSlow 26s ease-in-out infinite',
            shake: 'shake .28s ease-out 1',
          }
        }
      }
    }
  </script>
  <style>
    html, body { height: 100%; }
    .doc-card { background: rgba(255,255,255,0.15); backdrop-filter: blur(2px); border: 1px solid rgba(255,255,255,0.25); }
    .flow-line { background: linear-gradient(90deg, rgba(255,255,255,0.15), rgba(255,255,255,0.35), rgba(255,255,255,0.15)); height: 2px; }
    .btn-press:active { transform: scale(0.98); }
    /* Reposition password eye icons for injected forms */
    #formHost .floating-label-group { position: relative; }
    #formHost .password-toggle, #formHost .floating-label-group .password-toggle {
      position: absolute !important;
      left: auto !important;
      right: 12px !important;
      top: 50% !important;
      transform: translateY(-50%) !important;
      z-index: 20;
      color: #64748b;
      cursor: pointer;
    }
    #formHost .password-toggle i { font-size: 18px; line-height: 1; display: inline-block; }
    #formHost input[type="password"], #formHost input[type="text"] { padding-right: 3rem !important; }
    /* Normalize Create Account (register) layout inside container */
    #formHost .auth-pane { max-height: none !important; overflow: visible !important; padding: 0 !important; }
    #formHost .auth-pane form { width: 100% !important; }
    #formHost .auth-pane .floating-label-group { margin-bottom: 1rem !important; }
    #formHost .auth-pane h2 { margin-bottom: 1.25rem !important; }
    /* CHRMO theme enforcement for injected forms */
    #formHost input:focus, #formHost select:focus, #formHost textarea:focus {
      outline: none !important;
      box-shadow: 0 0 0 2px rgba(14,165,233,0.25) !important;
      border-color: #0ea5e9 !important;
    }
    #formHost button[type="submit"], #formHost .primary-action {
      background: #0ea5e9 !important; color: #ffffff !important; border-radius: 0.75rem;
      transition: transform .12s ease, background-color .15s ease;
    }
    #formHost button[type="submit"]:hover, #formHost .primary-action:hover { background: #0284c7 !important; }
  </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-sky-50 to-sky-100 flex items-stretch">
  <div class="w-full max-w-6xl mx-auto my-6 bg-white rounded-2xl shadow-xl overflow-hidden grid grid-cols-1 md:grid-cols-2">
    <!-- Panel A: Branding + Animation -->
    <aside class="relative bg-gradient-to-br from-primary-400 to-primary-500 text-white p-8 md:p-10 flex flex-col justify-between">
      <div>
        <div class="flex items-center gap-4 mb-6">
          <img src="hr.png" alt="CHRMO" class="w-16 h-16 rounded-full shadow-md" />
          <div>
            <div class="text-xs uppercase tracking-wider opacity-90">Panabo City Government</div>
            <div class="text-lg font-semibold">CHRMO Document System</div>
          </div>
        </div>
        <h1 class="text-2xl md:text-3xl font-bold leading-tight">Welcome Back to the CHRMO Document System</h1>
        <p class="mt-2 text-primary-100">Track and manage your documents securely.</p>
        <div class="mt-6 flex gap-3">
          <button id="showLogin" class="px-4 py-2 rounded-xl border border-white/80 text-white font-medium hover:bg-white/10 focus:ring-2 focus:ring-white/60 btn-press">Sign In</button>
          <button id="showRegister" class="px-4 py-2 rounded-xl border border-white/80 text-white font-medium hover:bg-white/10 focus:ring-2 focus:ring-white/60 btn-press">Register Now</button>
        </div>
      </div>
      <div class="relative mt-10">
        <!-- Animated document cards (abstract papers) -->
        <div class="absolute -left-4 top-0 w-28 h-36 rounded-xl doc-card animate-float animate-rotateSlow"></div>
        <div class="absolute left-24 top-10 w-24 h-28 rounded-lg doc-card animate-floatSlow animate-rotateSlow" style="animation-delay: 4s"></div>
        <div class="absolute left-16 -top-6 w-20 h-24 rounded-md doc-card animate-float" style="animation-delay: 2s"></div>
        <div class="absolute right-6 top-14 w-16 h-20 rounded-md doc-card animate-floatSlow" style="animation-delay: 6s"></div>
        <div class="absolute right-20 -top-4 w-24 h-28 rounded-lg doc-card animate-float animate-rotateSlow" style="animation-delay: 8s"></div>
        <!-- Curved workflow lines (low-opacity primary) -->
        <svg class="absolute inset-x-6 -bottom-2 opacity-60" height="80" viewBox="0 0 360 80" fill="none">
          <path d="M0 60 C 90 20, 180 20, 360 60" stroke="rgba(255,255,255,0.25)" stroke-width="2" class="animate-drift" />
        </svg>
        <svg class="absolute inset-x-10 bottom-8 opacity-50" height="70" viewBox="0 0 360 70" fill="none" style="animation-duration:30s">
          <path d="M0 40 C 80 5, 200 5, 360 40" stroke="rgba(255,255,255,0.2)" stroke-width="2" class="animate-drift" />
        </svg>
        <svg class="absolute inset-x-12 bottom-16 opacity-40" height="60" viewBox="0 0 360 60" fill="none" style="animation-duration:36s">
          <path d="M0 30 C 120 0, 240 0, 360 30" stroke="rgba(255,255,255,0.18)" stroke-width="2" class="animate-drift" />
        </svg>
      </div>
      <div class="text-xs text-primary-200">© 2025 CHRMO Document Tracking</div>
    </aside>

    <!-- Panel B: Dynamic Form -->
    <main class="relative p-6 md:p-10">
      <div id="formHost" class="animate-slideIn"></div>
    </main>
  </div>

  <script>
    const routes = {
      login: 'log-in.php',
      register: 'register.php'
    };
    let current = 'login';

    let cleanupInjectedStyles = () => {};

    async function loadForm(which, animateDirection = 1) {
      current = which;
      const host = document.getElementById('formHost');
      // reset classes
      host.classList.remove('animate-slideLeft','animate-slideRight');
      host.style.opacity = '0';
      // cleanup any previously injected styles
      cleanupInjectedStyles();
      try {
        const res = await fetch(routes[which], { credentials: 'same-origin' });
        const html = await res.text();
        const dom = new DOMParser().parseFromString(html, 'text/html');
        const wrapper = document.createElement('div');
        wrapper.className = 'max-w-md mx-auto';
        if (which === 'register') {
          // Extract the right pane (.auth-pane) and any inline <style> blocks to preserve exact styling
          const pane = dom.querySelector('.auth-pane') || dom.querySelector('form') || dom.body;
          // inject styles from <style> tags into document head, track for cleanup
          const injected = [];
          dom.querySelectorAll('style').forEach((s, idx) => {
            const copy = document.createElement('style');
            copy.setAttribute('data-injected-register', '1');
            copy.textContent = s.textContent || '';
            document.head.appendChild(copy);
            injected.push(copy);
          });

        // Remove any embedded promo sections like "Don't have an account?" or a secondary "Register Now"
        const purgeTexts = ["Don't have an account?", 'Register Now'];
        function purgePromos(root){
          const candidates = root.querySelectorAll('a, button, div, p, span');
          candidates.forEach(el => {
            const txt = (el.textContent || '').trim();
            if (!txt) return;
            if (purgeTexts.some(t => txt.includes(t))) {
              // remove the closest block container
              let node = el;
              while (node && node !== root && !(node.tagName && ['DIV','SECTION','FOOTER'].includes(node.tagName))) {
                node = node.parentElement;
              }
              (node || el).remove();
            }
          });
        }
        purgePromos(host);
          cleanupInjectedStyles = () => { injected.forEach(el => el.remove()); cleanupInjectedStyles = () => {}; };
          wrapper.innerHTML = '';
          wrapper.appendChild(pane.cloneNode(true));
        } else {
          // Login view: keep compact heading and inject its main form
          let form = dom.querySelector('form') || dom.body;
          wrapper.innerHTML = `
            <div class="mb-6 text-center">
              <h2 class="text-2xl font-bold text-gray-800">Sign in to your account</h2>
            </div>
          `;
          wrapper.appendChild(form);
        }
        host.innerHTML = '';
        host.appendChild(wrapper);

        // No subordinate link; use left panel buttons

        // Enhance buttons with press feedback
        host.querySelectorAll('button, [type="submit"]').forEach(btn => {
          btn.classList.add('btn-press');
          btn.addEventListener('click', () => {
            btn.style.transform = 'scale(0.98)';
            setTimeout(()=> btn.style.transform = '', 120);
          });
        });

        // Reposition any password eye icons precisely to the vertical center of their input
        function positionToggles() {
          host.querySelectorAll('.password-toggle').forEach(tog => {
            // find nearest input within same group
            let group = tog.closest('.floating-label-group') || host;
            let input = group.querySelector('input[type="password"]');
            if (!input) return;
            // ensure group is positioned
            if (getComputedStyle(group).position === 'static') group.style.position = 'relative';
            // make toggle absolutely positioned right-inside
            tog.style.position = 'absolute';
            tog.style.left = 'auto';
            tog.style.right = '12px';
            // compute top based on input box
            const rect = input.getBoundingClientRect();
            const groupRect = group.getBoundingClientRect();
            const centerY = (rect.top - groupRect.top) + rect.height / 2;
            tog.style.top = centerY + 'px';
            tog.style.transform = 'translateY(-50%)';
            tog.style.zIndex = '20';
          });
        }
        positionToggles();
        window.addEventListener('resize', positionToggles);
        // also recalc when inputs focus to avoid layout shifts
        host.querySelectorAll('input').forEach(i=>{
          i.addEventListener('focus', positionToggles);
          i.addEventListener('blur', positionToggles);
        });

        // Highlight inputs on focus (primary color) and shake invalid
        host.querySelectorAll('input, select, textarea').forEach(inp => {
          inp.addEventListener('invalid', () => {
            inp.classList.add('animate-shake');
            setTimeout(()=> inp.classList.remove('animate-shake'), 320);
          });
        });

      } catch (e) {
        host.innerHTML = `<div class="text-center text-red-600">Failed to load form. Please refresh.</div>`;
      } finally {
        // Slide in with direction-based animation
        requestAnimationFrame(()=>{
          host.style.opacity = '1';
          if (animateDirection > 0) {
            host.classList.add('animate-slideLeft');
          } else {
            host.classList.add('animate-slideRight');
          }
        });
      }
    }

    document.getElementById('showLogin').addEventListener('click', ()=> loadForm('login', -1));
    document.getElementById('showRegister').addEventListener('click', ()=> loadForm('register', 1));

    // Initial load
    loadForm('login', 1);
  </script>
</body>
</html>
