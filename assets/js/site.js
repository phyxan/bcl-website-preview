/* Peter Barrett Criminal Defense - site.js
   Scroll reveals, counters, sticky header, mobile nav, TOC scrollspy,
   article progress bar, demo form handling. Respects reduced motion. */
(function () {
  "use strict";
  var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

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

  /* demo form: prevent submit, show success (production wires to CRM/intake) */
  document.querySelectorAll("form[data-demo]").forEach(function (f) {
    f.addEventListener("submit", function (e) {
      e.preventDefault();
      var ok = f.querySelector(".form-success");
      if (ok) {
        ok.style.display = "block";
        ok.scrollIntoView({ behavior: reduced ? "auto" : "smooth", block: "center" });
      }
      f.querySelectorAll("input,select,textarea,button").forEach(function (el) {
        el.disabled = true;
      });
    });
  });

  /* current year */
  document.querySelectorAll(".yr").forEach(function (el) {
    el.textContent = new Date().getFullYear();
  });
})();
