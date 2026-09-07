/* Peter Barrett Criminal Defense - site.js
   Scroll reveals, counters, sticky header, mobile nav, TOC scrollspy,
   article progress bar, demo form handling. Respects reduced motion. */
(function () {
  "use strict";
  var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* Cloudflare Turnstile CAPTCHA. Set the site key to enable it; empty = dormant. */
  var TURNSTILE_SITEKEY = "";
  var _tsLoading = false, _tsQueue = [];
  function ensureTurnstile(cb) {
    if (window.turnstile) { cb(); return; }
    _tsQueue.push(cb);
    if (_tsLoading) return;
    _tsLoading = true;
    var s = document.createElement("script");
    s.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    s.async = true; s.defer = true;
    s.onload = function () { _tsQueue.forEach(function (fn) { fn(); }); _tsQueue = []; };
    document.head.appendChild(s);
  }

  /* sticky header shadow */
  var header = document.querySelector("header.site");
  if (header) {
    var onScrollHead = function () {
      header.classList.toggle("scrolled", window.scrollY > 8);
    };
    window.addEventListener("scroll", onScrollHead, { passive: true });
    onScrollHead();
  }

  /* mobile nav */
  var btn = document.querySelector(".menu-btn");
  var nav = document.querySelector("nav.main");
  if (btn && nav) {
    btn.addEventListener("click", function () {
      var open = nav.classList.toggle("open");
      btn.classList.toggle("open", open);
      btn.setAttribute("aria-expanded", open ? "true" : "false");
      document.body.style.overflow = open ? "hidden" : "";
    });
    nav.addEventListener("click", function (e) {
      if (e.target.tagName === "A") {
        nav.classList.remove("open");
        btn.classList.remove("open");
        document.body.style.overflow = "";
      }
    });
  }

  /* reveal on scroll */
  if (!reduced && "IntersectionObserver" in window) {
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) {
            en.target.classList.add("in");
            io.unobserve(en.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
    );
    document
      .querySelectorAll(".reveal, .reveal-l, .reveal-r, .stagger")
      .forEach(function (el) { io.observe(el); });
  } else {
    document
      .querySelectorAll(".reveal, .reveal-l, .reveal-r, .stagger")
      .forEach(function (el) { el.classList.add("in"); });
  }

  /* animated counters: <span class="count" data-to="100" data-suffix="+"> */
  var counters = document.querySelectorAll(".count");
  if (counters.length) {
    var animate = function (el) {
      var to = parseFloat(el.getAttribute("data-to") || "0");
      var suffix = el.getAttribute("data-suffix") || "";
      var decimals = (String(el.getAttribute("data-to")).split(".")[1] || "").length;
      if (reduced) { el.textContent = to.toFixed(decimals) + suffix; return; }
      var dur = 1400, t0 = null;
      var tick = function (t) {
        if (!t0) t0 = t;
        var p = Math.min((t - t0) / dur, 1);
        var eased = 1 - Math.pow(1 - p, 3);
        el.textContent = (to * eased).toFixed(decimals) + suffix;
        if (p < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    };
    var cio = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) { animate(en.target); cio.unobserve(en.target); }
        });
      },
      { threshold: 0.5 }
    );
    counters.forEach(function (c) { cio.observe(c); });
  }

  /* article reading progress */
  var prog = document.querySelector(".progress");
  var post = document.querySelector("article.post");
  if (prog && post) {
    var onScrollProg = function () {
      var r = post.getBoundingClientRect();
      var total = r.height - window.innerHeight;
      var done = Math.min(Math.max(-r.top, 0), total);
      prog.style.width = (total > 0 ? (done / total) * 100 : 0) + "%";
    };
    window.addEventListener("scroll", onScrollProg, { passive: true });
    onScrollProg();
  }

  /* TOC scrollspy */
  var tocLinks = document.querySelectorAll(".toc a[href^='#']");
  if (tocLinks.length && "IntersectionObserver" in window) {
    var map = {};
    tocLinks.forEach(function (a) {
      var id = a.getAttribute("href").slice(1);
      var sec = document.getElementById(id);
      if (sec) map[id] = a.parentElement;
    });
    var sio = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting && map[en.target.id]) {
            tocLinks.forEach(function (a) { a.parentElement.classList.remove("active"); });
            map[en.target.id].classList.add("active");
          }
        });
      },
      { rootMargin: "-25% 0px -65% 0px" }
    );
    Object.keys(map).forEach(function (id) {
      sio.observe(document.getElementById(id));
    });
  }

  /* Free Case Review intake: submit to the first-party PHP handler via fetch.
     If JS is off, the form still posts natively to its action and PHP redirects. */
  document.querySelectorAll("form[data-intake]").forEach(function (f) {
    var tsField = f.querySelector('input[name="ts"]');
    if (tsField) tsField.value = Math.floor(Date.now() / 1000);

    var tsHolder = f.querySelector(".cf-turnstile-holder");
    var tsWidget = null;
    if (TURNSTILE_SITEKEY && tsHolder) {
      ensureTurnstile(function () {
        try { tsWidget = window.turnstile.render(tsHolder, { sitekey: TURNSTILE_SITEKEY }); } catch (e) {}
      });
    }
    var resetTs = function () {
      if (tsWidget !== null && window.turnstile) {
        try { window.turnstile.reset(tsWidget); } catch (e) {}
      }
    };

    var errBox = f.querySelector(".form-error");
    var okBox = f.querySelector(".form-success");
    var btn = f.querySelector('button[type="submit"]');
    var btnText = btn ? btn.textContent : "";

    var esc = function (s) {
      return String(s).replace(/[&<>"]/g, function (c) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
      });
    };
    var clearErrors = function () {
      if (errBox) { errBox.hidden = true; errBox.innerHTML = ""; }
      f.querySelectorAll(".field-invalid").forEach(function (el) {
        el.classList.remove("field-invalid");
      });
    };
    var showErrors = function (errors, generic) {
      if (!errBox) return;
      var msgs = [];
      if (errors && typeof errors === "object") {
        Object.keys(errors).forEach(function (k) {
          msgs.push(errors[k]);
          var field = f.querySelector('[name="' + k + '"]');
          if (field) field.classList.add("field-invalid");
        });
      }
      if (!msgs.length) msgs.push(generic || "Something went wrong. Please call (214) 526-0555.");
      errBox.innerHTML = msgs.length > 1
        ? "<strong>Please check the form:</strong><ul><li>" + msgs.map(esc).join("</li><li>") + "</li></ul>"
        : esc(msgs[0]);
      errBox.hidden = false;
      errBox.scrollIntoView({ behavior: reduced ? "auto" : "smooth", block: "center" });
    };

    f.addEventListener("submit", function (e) {
      e.preventDefault();
      clearErrors();
      if (btn) { btn.disabled = true; btn.textContent = "Sending…"; }

      fetch(f.action, {
        method: "POST",
        body: new FormData(f),
        headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
        credentials: "same-origin"
      })
        .then(function (r) {
          return r.json().catch(function () { return { ok: r.ok }; });
        })
        .then(function (data) {
          if (data && data.ok) {
            clearErrors();
            if (okBox) {
              okBox.style.display = "block";
              okBox.scrollIntoView({ behavior: reduced ? "auto" : "smooth", block: "center" });
            }
            f.querySelectorAll("input,select,textarea,button").forEach(function (el) {
              el.disabled = true;
            });
          } else {
            if (btn) { btn.disabled = false; btn.textContent = btnText; }
            resetTs();
            if (data && data.error === "rate_limited") {
              showErrors(null, "You've sent this several times. Please call (214) 526-0555 and we'll help right away.");
            } else if (data && data.error === "captcha_failed") {
              showErrors(null, "The spam check didn't pass. Please try again, or call (214) 526-0555.");
            } else {
              showErrors(data && data.errors, "We couldn't submit that. Please try again, or call (214) 526-0555.");
            }
          }
        })
        .catch(function () {
          if (btn) { btn.disabled = false; btn.textContent = btnText; }
          resetTs();
          showErrors(null, "Network problem submitting the form. Please try again, or call (214) 526-0555.");
        });
    });
  });

  /* current year */
  document.querySelectorAll(".yr").forEach(function (el) {
    el.textContent = new Date().getFullYear();
  });
})();
