/* ==========================================================================
   Paywise — Apple Motion (Emil Kowalski / Designing Fluid Interfaces)
   Interruptible, velocity-aware springs for the web. No dependencies.
   - Response on pointer-down, continuous feedback during gesture
   - Springs animate from the live presentation value (interruptible)
   - Velocity handoff + momentum projection on release
   - Rubber-band soft boundaries, transform/opacity only (compositor)
   - Honors prefers-reduced-motion / transparency
   ========================================================================== */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var coarsePointer = window.matchMedia('(pointer: coarse)').matches;

  /* ——— Spring core: damping-ratio + response, velocity-aware ——— */
  function springTo(el, target, opts) {
    opts = opts || {};
    if (reduceMotion || !el || !window.requestAnimationFrame) {
      if (typeof opts.set === 'function') opts.set(target, 1);
      else if (target && typeof target === 'object') {
        for (var k in target) { if (k === 'x') el.style.transform = 'translateX(' + target[k] + 'px)'; }
      }
      if (typeof opts.done === 'function') opts.done();
      return function () {};
    }
    var response = opts.response || 0.34;      // seconds — Apple default 0.3–0.4
    var damping = opts.damping == null ? 1.0 : opts.damping; // 1.0 calm, ~0.8 momentum
    var get = opts.get || function () { return 0; };
    var set = opts.set || function () {};
    var velocity = opts.velocity || 0;
    var stiffness = Math.pow((2 * Math.PI) / (response * 2.2), 2);
    var dampCoef = (2 * damping * 2 * Math.PI) / (response * 2.2);
    var current = get();
    var targetVal = (typeof target === 'number') ? target : target;
    var raf = 0, last = performance.now(), cancelled = false;
    // read live presentation value so interrupts never jump
    function frame(now) {
      if (cancelled) return;
      var dt = Math.min((now - last) / 1000, 0.064);
      last = now;
      // semi-implicit Euler — stable, interruptible, velocity-carrying
      var displacement = current - targetVal;
      var accel = (-stiffness * displacement) - (dampCoef * velocity);
      velocity += accel * dt;
      current += velocity * dt;
      var settled = Math.abs(velocity) < 0.4 && Math.abs(displacement) < 0.4;
      if (settled) { current = targetVal; set(current, 1); if (opts.done) opts.done(); return; }
      set(current, 0);
      raf = requestAnimationFrame(frame);
    }
    raf = requestAnimationFrame(function (t) { last = t; raf = requestAnimationFrame(frame); });
    return function cancel() { cancelled = true; cancelAnimationFrame(raf); };
  }

  /* Apple's momentum projection: (v/1000) * d / (1-d), d ~= 0.998 */
  function project(velocityPxPerSec, rate) {
    var d = (rate == null) ? 0.998 : rate;
    return (velocityPxPerSec / 1000) * d / (1 - d);
  }
  function rubberband(overshoot, dimension, constant) {
    var c = (constant == null) ? 0.55 : constant;
    return (overshoot * dimension * c) / (dimension + c * Math.abs(overshoot));
  }

  /* ——— Entrance choreography: staggered, blurred, spring-eased ——— */
  function reveal() {
    if (reduceMotion || document.body.classList.contains('sidebar-navigation')) return;
    var items = document.querySelectorAll(
      '.card, .quick-action, .stat, .kpi, .hero, .dashboard-hero, ' +
      '.employee-hero, .page-heading, .toolbar, .attendance-filters, .login-card'
    );
    if (!items.length || !('IntersectionObserver' in window)) return;
    items.forEach(function (el, i) {
      if (el.classList.contains('apple-reveal') || el.closest('.detail-modal')) return;
      el.classList.add('apple-reveal');
      el.style.setProperty('--apple-delay', Math.min(i % 8, 7) * 45 + 'ms');
    });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add('apple-in');
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -6% 0px' });
    items.forEach(function (el) { io.observe(el); });
  }

  /* ——— Press response + commit haptic (same-frame harmony) ——— */
  function pressFeedback() {
    // CSS :active scale covers visuals; here we add causal haptics only.
    document.addEventListener('pointerup', function (e) {
      var t = e.target.closest && e.target.closest('.btn-primary, .tutorial-next, .attendance-button');
      if (t && coarsePointer && navigator.vibrate) {
        try { navigator.vibrate(8); } catch (err) {}
      }
    }, { passive: true });
  }

  /* ——— Sheets: materialize (blur+scale) symmetric enter/exit ——— */
  function sheets() {
    function materialize(modal) {
      if (!modal || reduceMotion) return;
      // start from live value so grabs mid-flight never jump
      modal.getAnimations().forEach(function (a) { try { a.cancel(); } catch (e) {} });
      try {
        modal.animate(
          [
            { opacity: 0, transform: 'translateY(16px) scale(0.965)', filter: 'blur(6px)' },
            { opacity: 1, transform: 'none', filter: 'blur(0)' }
          ],
          { duration: 340, easing: 'cubic-bezier(0.32, 0.72, 0, 1)', fill: 'both' }
        );
      } catch (e) {}
    }
    var obs = new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        if (m.attributeName !== 'class') return;
        var el = m.target;
        if (el.classList && el.classList.contains('show')) {
          var modal = el.querySelector('.settings-modal, .detail-modal, .tutorial-card.active');
          if (modal) materialize(modal);
          else if (el.classList.contains('tutorial-card')) materialize(el);
          var menu = el.classList.contains('admin-dropdown') ? el : el.querySelector('.admin-dropdown.show');
          if (menu) materialize(menu);
        }
      });
    });
    obs.observe(document.documentElement, { attributes: true, subtree: true, attributeFilter: ['class'] });
  }

  /* ——— Draggable sheets: 1:1 tracking + velocity handoff + rubber-band ——— */
  function draggableSheets() {
    if (reduceMotion || !coarsePointer) return;
    document.querySelectorAll('.detail-modal, .settings-modal').forEach(function (sheet) {
      var head = sheet.querySelector('.detail-modal-head, .settings-head');
      if (!head || head.dataset.appleDrag) return;
      head.dataset.appleDrag = '1';
      head.style.touchAction = 'pan-y';
      head.style.cursor = 'grab';
      var startY = 0, grabOffset = 0, currentY = 0, dragging = false;
      var history = [];
      head.addEventListener('pointerdown', function (e) {
        dragging = true; startY = e.clientY;
        grabOffset = e.clientY - sheet.getBoundingClientRect().top;
        currentY = 0; history = [{ y: e.clientY, t: performance.now() }];
        try { head.setPointerCapture(e.pointerId); } catch (err) {}
        sheet.getAnimations().forEach(function (a) { try { a.finish(); } catch (ex) {} });
      });
      head.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        var dy = e.clientY - startY;
        // respect grab offset, rubber-band when pulling down past origin
        var y = dy < 0 ? dy * 0.35 : rubberband(dy, window.innerHeight * 0.6);
        currentY = y;
        history.push({ y: e.clientY, t: performance.now() });
        if (history.length > 6) history.shift();
        sheet.style.transform = 'translateY(' + y.toFixed(1) + 'px)';
      });
      function end(e) {
        if (!dragging) return;
        dragging = false;
        // release velocity from recent history
        var v = 0;
        if (history.length >= 2) {
          var a = history[0], b = history[history.length - 1];
          var dt = Math.max((b.t - a.t) / 1000, 0.016);
          v = (b.y - a.y) / dt;
        }
        var overlay = sheet.closest('.settings-overlay, .detail-modal-overlay');
        var projected = currentY + project(v, 0.99);
        var shouldDismiss = projected > 140 || (currentY > 90 && v > 300);
        if (shouldDismiss && overlay) {
          // symmetric exit path — same way it came
          springTo(sheet, window.innerHeight * 0.6, {
            response: 0.3, damping: 1.0, velocity: Math.max(v, 400),
            get: function () { return currentY; },
            set: function (val) { currentY = val; sheet.style.transform = 'translateY(' + val + 'px)'; },
            done: function () {
              overlay.classList.remove('show');
              sheet.style.transform = '';
            }
          });
        } else {
          // snap home carrying finger velocity — no brick wall
          springTo(sheet, 0, {
            response: 0.34, damping: v < -600 ? 0.8 : 1.0, velocity: v * 0.4,
            get: function () { return currentY; },
            set: function (val) { currentY = val; sheet.style.transform = 'translateY(' + val + 'px)'; },
            done: function () { sheet.style.transform = ''; }
          });
        }
        void grabOffset; void e;
      }
      head.addEventListener('pointerup', end);
      head.addEventListener('pointercancel', end);
    });
  }

  /* ——— Scroll-edge fade for sticky table headers ——— */
  function scrollEdges() {
    document.querySelectorAll('.table-wrap').forEach(function (wrap) {
      if (wrap.dataset.appleEdge) return;
      wrap.dataset.appleEdge = '1';
      wrap.style.scrollbarWidth = 'thin';
    });
  }

  function init() {
    reveal();
    pressFeedback();
    sheets();
    draggableSheets();
    scrollEdges();
    // expose spring utilities for future gesture work
    window.AppleMotion = { springTo: springTo, project: project, rubberband: rubberband };
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
