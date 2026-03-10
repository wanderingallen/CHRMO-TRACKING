/**
 * CHRMO Document Tracking - Smooth Interactions
 * Universal animation and interaction enhancements
 * Deployment-ready, performance-optimized
 */

(function () {
  "use strict";

  // === PAGE LOAD ANIMATIONS ===
  document.addEventListener("DOMContentLoaded", () => {
    // Inject global CSS to normalize sidebar badge alignment across pages
    (function ensureSidebarBadgeStyles() {
      if (document.getElementById("global-sidebar-badge-styles")) return;
      const css = `
        body .sidebar .menu-badge{position:absolute;right:12px;top:50%;transform:translateY(-50%)!important;height:20px;min-width:20px;padding:0 6px;border-radius:999px;line-height:20px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;pointer-events:none;}
        body .sidebar .menu-item.active .menu-badge{transform:translateY(-50%)!important}
        body .sidebar:not(:hover) .menu-badge{right:6px;top:6px;transform:none!important;display:inline-flex!important}
      `;
      const style = document.createElement("style");
      style.id = "global-sidebar-badge-styles";
      style.textContent = css;
      document.head.appendChild(style);
    })();

    // Inject small CSS for notification hover ring animation
    (function ensureNotifHoverStyles() {
      if (document.getElementById("global-notif-ring-styles")) return;
      const css = `
        .notification-icon { position:relative; }
        .notification-icon::after { content:''; position:absolute; inset:-4px; border-radius:999px; border:2px solid rgba(6,182,212,0.0); transform: scale(0.96); transition: border-color .18s ease, transform .18s ease; pointer-events:none; }
        .notification-icon:hover::after { border-color: rgba(6,182,212,0.5); transform: scale(1); }
      `;
      const style = document.createElement("style");
      style.id = "global-notif-ring-styles";
      style.textContent = css;
      document.head.appendChild(style);
    })();

    const disablePageAnim = document.body.classList.contains("no-page-anim");
    // Add page-enter animation to main content unless disabled
    const mainContent = document.querySelector(
      'main, .main-content, [role="main"]',
    );
    if (
      !disablePageAnim &&
      mainContent &&
      !mainContent.classList.contains("page-enter")
    ) {
      mainContent.classList.add("page-enter");
    }

    // Initialize card animations based on current page
    const initialPage = (
      document.getElementById("main-content-swap")?.getAttribute("data-page") ||
      ""
    ).toLowerCase();
    if (typeof window.normalizeCardAnimations === "function") {
      window.normalizeCardAnimations(initialPage);
    }

    // Sidebar hover debounce to avoid stutter - uses requestAnimationFrame for smooth GPU-accelerated transitions
    (function initSidebarHoverDebounce() {
      const sidebar = document.querySelector(".sidebar");
      if (!sidebar) return;
      let enterT = null,
        leaveT = null;
      const ENTER_DELAY = 80,
        LEAVE_DELAY = 120; // Reduced for snappier response

      // Pre-promote layer for GPU acceleration
      sidebar.style.transform = "translateZ(0)";
      sidebar.style.willChange = "width";

      sidebar.addEventListener(
        "mouseenter",
        () => {
          if (leaveT) {
            clearTimeout(leaveT);
            leaveT = null;
          }
          enterT = setTimeout(() => {
            requestAnimationFrame(() => {
              document.body.classList.add("sidebar-hover-open");
            });
          }, ENTER_DELAY);
        },
        { passive: true },
      );

      sidebar.addEventListener(
        "mouseleave",
        () => {
          if (enterT) {
            clearTimeout(enterT);
            enterT = null;
          }
          leaveT = setTimeout(() => {
            requestAnimationFrame(() => {
              document.body.classList.remove("sidebar-hover-open");
            });
          }, LEAVE_DELAY);
        },
        { passive: true },
      );

      // Clean up will-change after initial render to free memory
      setTimeout(() => {
        sidebar.style.willChange = "auto";
      }, 2000);
    })();

    // === LOGOUT CONFIRMATION MODAL ===
    function initLogoutConfirm() {
      const selector = 'a[href$="logout.php"]';
      document.querySelectorAll(selector).forEach((link) => {
        // Avoid double-binding
        if (link.dataset.logoutBind === "1") return;
        link.dataset.logoutBind = "1";

        link.addEventListener("click", function (e) {
          // Allow modified clicks (new tab etc.)
          if (
            e.metaKey ||
            e.ctrlKey ||
            e.shiftKey ||
            e.altKey ||
            this.target === "_blank"
          )
            return;
          e.preventDefault();
          showLogoutModal(this.href);
        });
      });
    }

    function showLogoutModal(targetUrl) {
      // Prevent duplicate modal
      if (document.querySelector(".logout-modal-backdrop")) return;

      // Ensure minimal styles exist even if animations.css failed to load
      ensureLogoutModalStyles();

      const backdrop = document.createElement("div");
      backdrop.className = "logout-modal-backdrop";
      backdrop.innerHTML = `
      <div class="logout-modal" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
        <div class="logout-modal-header">
          <span id="logoutTitle">Confirm Logout</span>
        </div>
        <div class="logout-modal-body">
          Are you sure you want to log out?
        </div>
        <div class="logout-modal-actions">
          <button type="button" class="btn-cancel">Cancel</button>
          <button type="button" class="btn-logout-confirm">Logout</button>
        </div>
      </div>`;

      // Lock background scroll
      const prevOverflow = document.body.style.overflow;
      document.body.style.overflow = "hidden";

      document.body.appendChild(backdrop);
      // force reflow then show
      requestAnimationFrame(() => {
        backdrop.classList.add("show");
        const modal = backdrop.querySelector(".logout-modal");
        modal.style.transform = "translateY(0) scale(1)";
        // focus
        const cancelBtn = backdrop.querySelector(".btn-cancel");
        if (cancelBtn) cancelBtn.focus();
      });

      const cleanup = () => {
        const modal = backdrop.querySelector(".logout-modal");
        if (modal) modal.style.transform = "translateY(12px) scale(0.98)";
        backdrop.classList.remove("show");
        setTimeout(() => {
          backdrop.remove();
          // Restore background scroll
          document.body.style.overflow = prevOverflow || "";
        }, 200);
      };

      backdrop.addEventListener("click", (ev) => {
        if (ev.target === backdrop) cleanup();
      });
      backdrop.querySelector(".btn-cancel").addEventListener("click", cleanup);
      backdrop
        .querySelector(".btn-logout-confirm")
        .addEventListener("click", () => {
          // Proceed to logout
          window.location.href = targetUrl;
        });
      document.addEventListener("keydown", function escHandler(ev) {
        if (ev.key === "Escape") {
          ev.preventDefault();
          cleanup();
          document.removeEventListener("keydown", escHandler);
        }
      });
    }

    function ensureLogoutModalStyles() {
      if (document.getElementById("logout-modal-fallback-styles")) return;
      const style = document.createElement("style");
      style.id = "logout-modal-fallback-styles";
      style.textContent = `
      .logout-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:10000;opacity:0;transition:opacity .2s ease}
      .logout-modal-backdrop.show{opacity:1}
      .logout-modal{background:#fff;border-radius:14px;width:92%;max-width:420px;box-shadow:0 10px 28px rgba(0,0,0,.12);overflow:hidden;transform:translateY(12px) scale(.98);transition:transform .22s cubic-bezier(.16,1,.3,1)}
      .logout-modal-header{background:linear-gradient(to right,#0e7490,#06b6d4);color:#fff;padding:14px 18px;font-weight:700}
      .logout-modal-body{padding:16px 18px;color:#263238}
      .logout-modal-actions{padding:14px 18px;display:flex;justify-content:flex-end;gap:10px;background:#F5F7FA}
      .btn-cancel{background:#e2e8f0;color:#1f2937;border:1px solid #cbd5e1;padding:8px 14px;border-radius:10px;font-weight:600}
      .btn-cancel:hover{background:#cbd5e1}
      .btn-logout-confirm{background:#ef4444;color:#fff;border:1px solid #e11d48;padding:8px 14px;border-radius:10px;font-weight:700}
      .btn-logout-confirm:hover{background:#dc2626}
    `;
      document.head.appendChild(style);
    }

    // Stagger card animations unless disabled
    if (!disablePageAnim) {
      const cards = document.querySelectorAll(
        '.card, .stat-card, [class*="card-"]',
      );
      cards.forEach((card, index) => {
        card.classList.add("card-enter");
        if (index < 4) {
          card.classList.add(`card-stagger-${index + 1}`);
        }
      });
    }

    // Add hover effects to interactive elements
    enhanceInteractiveElements();

    // Smooth scroll for anchor links
    enableSmoothScroll();

    // Add ripple effect to buttons
    addRippleEffect();

    // Enhance form inputs
    enhanceFormInputs();

    // Add loading states
    enhanceLoadingStates();

    // Enable page transition fade on navigation unless disabled
    if (!disablePageAnim) addPageTransition();

    // Initialize logout confirmation modal globally
    initLogoutConfirm();

    // Sidebar badges: define globally (without overriding page-specific versions)
    // Uses ETag conditional requests to avoid processing identical data (304)
    if (typeof window.loadSidebarBadges !== "function") {
      window.__sidebarEtag = null;
      window.loadSidebarBadges = async function () {
        const trackingBadge = document.getElementById("trackingBadge");
        const archiveBadge = document.getElementById("archiveBadge");
        try {
          const headers = {};
          if (window.__sidebarEtag)
            headers["If-None-Match"] = window.__sidebarEtag;
          const res = await fetch("sidebar_stats.php", {
            cache: "no-cache",
            headers,
          });

          // 304 = data unchanged, skip processing entirely
          if (res.status === 304) return;

          const etag = res.headers.get("ETag");
          if (etag) window.__sidebarEtag = etag;

          const text = await res.text();
          let jsonText = text.trim();
          // Attempt tolerant JSON extraction in case of stray output
          if (!jsonText.startsWith("{")) {
            const first = jsonText.indexOf("{");
            const last = jsonText.lastIndexOf("}");
            if (first !== -1 && last !== -1 && last > first) {
              jsonText = jsonText.slice(first, last + 1);
            }
          }
          let data = {};
          try {
            data = JSON.parse(jsonText);
          } catch (e) {
            data = {};
          }
          if (trackingBadge) {
            const val = Number(data && data.pending_count);
            trackingBadge.textContent = String(!isNaN(val) ? val : 0);
            trackingBadge.style.display = "inline-block";
          }
          if (archiveBadge) {
            const valA = Number(data && data.archived_today);
            archiveBadge.textContent = String(!isNaN(valA) ? valA : 0);
            archiveBadge.style.display = "inline-block";
          }
        } catch (err) {
          if (trackingBadge) {
            trackingBadge.textContent = "0";
            trackingBadge.style.display = "inline-block";
          }
          if (archiveBadge) {
            archiveBadge.textContent = "0";
            archiveBadge.style.display = "inline-block";
          }
        }
      };
    }

    // Invoke once on load
    if (typeof window.loadSidebarBadges === "function") {
      window.loadSidebarBadges();
    }
  });

  // === STAGGER CARD ANIMATIONS ===
  function shouldSkipCardAnim(page) {
    const p = (page || "").toLowerCase();
    return p === "archive" || p === "stats" || p === "usercontrol";
  }

  window.normalizeCardAnimations = function (page) {
    const disablePageAnim = document.body.classList.contains("no-page-anim");
    // Clean previous classes/inline styles
    document
      .querySelectorAll('.card, .stat-card, [class*="card-"]')
      .forEach((card) => {
        card.classList.remove("card-enter");
        card.style.animationDelay = "";
      });
    // For flicker-prone pages, disable hover and stagger
    if (shouldSkipCardAnim(page)) {
      document
        .querySelectorAll(".stat-card")
        .forEach((el) => el.classList.add("no-hover"));
      return;
    }
    // Otherwise, apply light stagger
    if (!disablePageAnim) {
      const cards = document.querySelectorAll(
        '.card, .stat-card, [class*="card-"]',
      );
      cards.forEach((card, index) => {
        card.classList.add("card-enter");
        if (index < 4) card.style.animationDelay = index * 40 + "ms";
      });
    }
  };

  // === ENHANCE INTERACTIVE ELEMENTS ===
  function enhanceInteractiveElements() {
    // Add hover-lift to cards that don't have it (exclude .no-hover)
    const cards = document.querySelectorAll(
      '.card:not(.no-hover), .stat-card:not(.no-hover), [class*="card-"]:not(.no-hover)',
    );
    cards.forEach((card) => {
      if (
        !card.classList.contains("hover-lift") &&
        !card.classList.contains("no-hover")
      ) {
        card.classList.add("hover-lift");
      }
    });

    // Add btn-press to buttons
    const buttons = document.querySelectorAll(
      'button:not(.no-press), [type="submit"]:not(.no-press)',
    );
    buttons.forEach((btn) => {
      if (!btn.classList.contains("btn-press")) {
        btn.classList.add("btn-press");
      }
    });

    // Add link underline animation to text links (exclude specific classes)
    const textLinks = document.querySelectorAll(
      'a:not(.btn):not(.button):not([class*="btn-"]):not(.no-underline-animation)',
    );
    textLinks.forEach((link) => {
      if (
        !link.classList.contains("link-underline") &&
        !link.classList.contains("no-underline-animation") &&
        link.textContent.trim()
      ) {
        link.classList.add("link-underline");
      }
    });
  }

  // === SMOOTH SCROLL ===
  function enableSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
      anchor.addEventListener("click", function (e) {
        const href = this.getAttribute("href");
        if (href === "#" || href === "#!") return;

        const target = document.querySelector(href);
        if (target) {
          e.preventDefault();
          target.scrollIntoView({
            behavior: "smooth",
            block: "start",
          });
        }
      });
    });
  }

  // === RIPPLE EFFECT ===
  function addRippleEffect() {
    document.addEventListener("click", function (e) {
      const button = e.target.closest(".btn-ripple, button.btn-press");
      if (!button) return;

      const ripple = document.createElement("span");
      const rect = button.getBoundingClientRect();
      const size = Math.max(rect.width, rect.height);
      const x = e.clientX - rect.left - size / 2;
      const y = e.clientY - rect.top - size / 2;

      ripple.style.cssText = `
        position: absolute;
        width: ${size}px;
        height: ${size}px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.5);
        left: ${x}px;
        top: ${y}px;
        pointer-events: none;
        transform: scale(0);
        animation: ripple-animation 0.6s ease-out;
      `;

      button.style.position = "relative";
      button.style.overflow = "hidden";
      button.appendChild(ripple);

      setTimeout(() => ripple.remove(), 600);
    });

    // Add ripple animation keyframes if not exists
    if (!document.querySelector("#ripple-keyframes")) {
      const style = document.createElement("style");
      style.id = "ripple-keyframes";
      style.textContent = `
        @keyframes ripple-animation {
          to {
            transform: scale(4);
            opacity: 0;
          }
        }
      `;
      document.head.appendChild(style);
    }
  }

  // === ENHANCE FORM INPUTS ===
  function enhanceFormInputs() {
    const inputs = document.querySelectorAll(
      'input:not([type="checkbox"]):not([type="radio"]), select, textarea',
    );

    inputs.forEach((input) => {
      // Add focus lift effect
      if (!input.classList.contains("no-lift")) {
        input.classList.add("input-focus-lift");
      }

      // Add shake animation on invalid
      input.addEventListener("invalid", function (e) {
        e.preventDefault();
        this.classList.add("shake");
        setTimeout(() => this.classList.remove("shake"), 300);
      });

      // Add focus/blur animations
      input.addEventListener("focus", function () {
        this.style.transition = "all 0.2s ease";
      });

      input.addEventListener("blur", function () {
        this.style.transition = "all 0.2s ease";
      });
    });

    // Password toggles - no hover animation (removed as per user request)
  }

  // === LOADING STATES ===
  function enhanceLoadingStates() {
    // Add loading animation to forms on submit
    document.querySelectorAll("form").forEach((form) => {
      form.addEventListener("submit", function (e) {
        const submitBtn = this.querySelector('[type="submit"]');
        if (!submitBtn || submitBtn.disabled) return;

        // If client-side validation elsewhere prevented submit, do nothing
        if (e.defaultPrevented) return;

        // Guard: for login form, only disable when both fields are non-empty
        const idEl = this.querySelector("#identifier");
        const pwEl = this.querySelector("#password");
        if (idEl || pwEl) {
          const idOk = idEl ? idEl.value.trim().length > 0 : true;
          const pwOk = pwEl ? pwEl.value.trim().length > 0 : true;
          if (!idOk || !pwOk) return;
        }

        submitBtn.classList.add("loading");
        submitBtn.style.opacity = "0.7";
        submitBtn.style.pointerEvents = "none";

        // Add spinner if not exists
        if (!submitBtn.querySelector(".spinner")) {
          const spinner = document.createElement("i");
          spinner.className = "fas fa-spinner fa-spin spinner mr-2";
          submitBtn.prepend(spinner);
        }
      });
    });
  }

  // === PAGE TRANSITION ON NAVIGATION ===
  function addPageTransition() {
    const NAV_FADE_MS = 350; // match .page-exiting transition in CSS
    document
      .querySelectorAll('a:not([target="_blank"]):not([href^="#"])')
      .forEach((link) => {
        link.addEventListener("click", function (e) {
          const href = this.getAttribute("href");
          if (!href || href.startsWith("javascript:")) return;
          const url = new URL(href, window.location.href);
          if (url.origin !== window.location.origin) return;

          // Allow opting out via data-no-transition
          if (this.dataset.noTransition === "true") return;

          e.preventDefault();
          document.body.classList.add("page-exiting");
          // Force reflow so transition applies consistently
          void document.body.offsetWidth;
          setTimeout(() => {
            window.location.href = url.href;
          }, NAV_FADE_MS);
        });
      });
  }

  // === MODAL ANIMATIONS ===
  window.enhanceModal = function (modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    const content = modal.querySelector('.modal-content, [class*="modal-"]');
    if (content) {
      content.classList.add("modal-content-enter");
    }

    modal.classList.add("modal-backdrop");
  };

  // === TOAST NOTIFICATIONS ===
  window.showToast = function (message, type = "info", duration = 3000) {
    const toast = document.createElement("div");
    toast.className = `toast toast-enter ${type}`;
    toast.textContent = message;
    toast.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 12px 20px;
      border-radius: 8px;
      background: white;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      z-index: 10000;
      max-width: 300px;
    `;

    if (type === "success") toast.style.background = "#10b981";
    if (type === "error") toast.style.background = "#ef4444";
    if (type === "warning") toast.style.background = "#f59e0b";

    toast.style.color = type !== "info" ? "white" : "#1f2937";

    document.body.appendChild(toast);

    setTimeout(() => {
      toast.classList.remove("toast-enter");
      toast.classList.add("toast-exit");
      setTimeout(() => toast.remove(), 250);
    }, duration);
  };

  // === DROPDOWN ANIMATIONS ===
  document.addEventListener("click", function (e) {
    const dropdown = e.target.closest("[data-dropdown-toggle]");
    if (dropdown) {
      const targetId = dropdown.getAttribute("data-dropdown-toggle");
      const menu = document.getElementById(targetId);
      if (menu) {
        menu.classList.toggle("hidden");
        if (!menu.classList.contains("hidden")) {
          menu.classList.add("dropdown-enter");
        }
      }
    }
  });

  // === TABLE ROW ANIMATIONS ===
  if (!document.body.classList.contains("no-page-anim")) {
    const tableRows = document.querySelectorAll("tbody tr");
    tableRows.forEach((row, index) => {
      row.style.animation = `fadeIn 0.3s ease ${index * 0.03}s both`;
    });
  }

  // === PERFORMANCE: Remove will-change after animation ===
  document.addEventListener("animationend", function (e) {
    if (e.target.classList.contains("will-animate")) {
      e.target.classList.remove("will-animate");
      e.target.classList.add("animated");
    }
  });

  // Initialize page transitions (optional, can be disabled)
  function addPageTransition() {
    // ... (existing code above handles page-enter)
  }

  console.log("✨ CHRMO Smooth Interactions loaded");
})();
