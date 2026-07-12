/* ============================================================
   CONFIG — edit these before going live
   ============================================================ */

/* Payment links per event (YooKassa / CloudPayments / T-Bank pay link).
   While a link is empty, the pay button shows a "we'll contact you" note. */
const PAYMENT_LINKS = {
  jul16: "",
  jul17: "",
  jul26: "",
  jul27: "",
};

/* Where to send booking requests (your backend, Telegram-bot webhook,
   Formspree etc.). Leave empty to skip network submission. */
const BOOKING_ENDPOINT = "";

/* Seats left FOR MEN per event — honest scarcity, update by hand after
   each booking (or wire to a backend). Set to null to hide the counter. */
const SEATS_LEFT_MEN = {
  jul16: 6,
  jul17: 9,
  jul26: 11,
  jul27: 12,
};

const EVENTS = {
  jul16: { label: "четверг, 16 июля", group: "22–33" },
  jul17: { label: "пятница, 17 июля", group: "33–50" },
  jul26: { label: "воскресенье, 26 июля", group: "22–33" },
  jul27: { label: "понедельник, 27 июля", group: "33–50" },
};

const CONTACT_METHODS = {
  phone: { placeholder: "+7 900 000-00-00", type: "tel", inputmode: "tel", autocomplete: "tel" },
  telegram: { placeholder: "@username", type: "text", inputmode: "text", autocomplete: "off" },
  max: { placeholder: "+7 900 000-00-00 или @username", type: "text", inputmode: "text", autocomplete: "off" },
  instagram: { placeholder: "@username", type: "text", inputmode: "text", autocomplete: "off" },
};

/* ============================================================
   Seat counters on event cards
   ============================================================ */

function seatsWord(n) {
  const mod10 = n % 10, mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return "место";
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return "места";
  return "мест";
}

document.querySelectorAll("[data-seats]").forEach((el) => {
  const left = SEATS_LEFT_MEN[el.dataset.seats];
  if (typeof left === "number" && left > 0) {
    el.textContent = `Осталось ${left} ${seatsWord(left)} для мужчин`;
    el.hidden = false;
  }
});

/* ============================================================
   Nav background on scroll
   ============================================================ */

const nav = document.querySelector(".nav");
const onScrollNav = () => nav.classList.toggle("is-scrolled", window.scrollY > 12);
onScrollNav();
window.addEventListener("scroll", onScrollNav, { passive: true });

/* ============================================================
   Reveal-on-scroll
   ============================================================ */

const revealEls = document.querySelectorAll(".reveal");

if ("IntersectionObserver" in window) {
  const io = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        const el = entry.target;
        const delay = el.dataset.delay;
        if (delay) el.style.transitionDelay = `${delay}ms`;
        el.classList.add("is-in");
        io.unobserve(el);
      }
    },
    { threshold: 0.12, rootMargin: "0px 0px -8% 0px" }
  );
  revealEls.forEach((el) => io.observe(el));
} else {
  revealEls.forEach((el) => el.classList.add("is-in"));
}

/* ============================================================
   Sticky mobile CTA — appears after the hero
   ============================================================ */

const stickyCta = document.querySelector(".sticky-cta");
const stickyBtn = stickyCta.querySelector("button");
const hero = document.querySelector(".hero");
let modalOpen = false;

const updateStickyCta = () => {
  const passedHero = window.scrollY > hero.offsetHeight * 0.7;
  const show = passedHero && !modalOpen;
  stickyCta.classList.toggle("is-visible", show);
  stickyCta.setAttribute("aria-hidden", String(!show));
  stickyBtn.tabIndex = show ? 0 : -1;
};
updateStickyCta();
window.addEventListener("scroll", updateStickyCta, { passive: true });

/* ============================================================
   Booking modal
   ============================================================ */

const modal = document.getElementById("booking-modal");
const panel = modal.querySelector(".modal__panel");
const form = document.getElementById("booking-form");
const doneView = document.getElementById("booking-done");
const summaryEl = document.getElementById("booking-summary");
const payBtn = document.getElementById("booking-pay");
const payNote = document.getElementById("booking-pay-note");
const contactInput = document.getElementById("booking-contact");

let lastFocused = null;
let submittedData = null;

function openModal(eventId) {
  lastFocused = document.activeElement;
  modalOpen = true;

  form.hidden = false;
  doneView.hidden = true;
  clearErrors();

  const preset = eventId && EVENTS[eventId] ? eventId : "jul16";
  const radio = form.querySelector(`input[name="event"][value="${preset}"]`);
  if (radio) radio.checked = true;

  modal.hidden = false;
  document.body.style.overflow = "hidden";
  updateStickyCta();

  requestAnimationFrame(() => {
    modal.classList.add("is-open");
    panel.querySelector("input, button")?.focus({ preventScroll: true });
  });
}

function closeModal() {
  modalOpen = false;
  modal.classList.remove("is-open");
  document.body.style.overflow = "";
  updateStickyCta();

  const finish = () => { modal.hidden = true; };
  const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  reduced ? finish() : setTimeout(finish, 340);

  lastFocused?.focus({ preventScroll: true });
}

document.querySelectorAll("[data-open-booking]").forEach((btn) =>
  btn.addEventListener("click", () => openModal(btn.dataset.event))
);

modal.querySelectorAll("[data-close-booking]").forEach((el) =>
  el.addEventListener("click", closeModal)
);

document.addEventListener("keydown", (e) => {
  if (modal.hidden) return;
  if (e.key === "Escape") closeModal();
  if (e.key === "Tab") {
    const focusables = panel.querySelectorAll(
      'button, input, [href], [tabindex]:not([tabindex="-1"])'
    );
    const list = [...focusables].filter((el) => !el.disabled && el.offsetParent !== null);
    if (!list.length) return;
    const first = list[0];
    const last = list[list.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }
});

/* Contact method switches the input's keyboard and placeholder */

form.querySelectorAll('input[name="method"]').forEach((radio) =>
  radio.addEventListener("change", () => {
    const cfg = CONTACT_METHODS[radio.value];
    contactInput.type = cfg.type;
    contactInput.placeholder = cfg.placeholder;
    contactInput.setAttribute("inputmode", cfg.inputmode);
    contactInput.setAttribute("autocomplete", cfg.autocomplete);
    contactInput.value = "";
    hideError("contact");
  })
);

/* ============================================================
   Validation + submission
   ============================================================ */

function showError(key) {
  const el = form.querySelector(`[data-error-for="${key}"]`) ||
             document.querySelector(`[data-error-for="${key}"]`);
  if (el) el.hidden = false;
}

function hideError(key) {
  const el = document.querySelector(`[data-error-for="${key}"]`);
  if (el) el.hidden = true;
}

function clearErrors() {
  document.querySelectorAll(".booking__error[data-error-for]").forEach((el) => (el.hidden = true));
}

form.addEventListener("submit", (e) => {
  e.preventDefault();
  clearErrors();

  const data = Object.fromEntries(new FormData(form).entries());
  let firstInvalid = null;

  if (!data.event) {
    showError("event");
    firstInvalid = firstInvalid || form.querySelector('input[name="event"]');
  }

  if (!data.name || data.name.trim().length < 2) {
    showError("name");
    firstInvalid = firstInvalid || form.querySelector("#booking-name");
  }

  const contact = (data.contact || "").trim();
  const contactOk =
    data.method === "phone"
      ? contact.replace(/\D/g, "").length >= 10
      : contact.length >= 2;
  if (!contactOk) {
    showError("contact");
    firstInvalid = firstInvalid || contactInput;
  }

  if (!data.consent) {
    showError("consent");
    firstInvalid = firstInvalid || form.querySelector('input[name="consent"]');
  }

  if (firstInvalid) {
    firstInvalid.focus({ preventScroll: false });
    return;
  }

  submittedData = {
    event: data.event,
    name: data.name.trim(),
    method: data.method,
    contact,
    submittedAt: new Date().toISOString(),
  };

  if (BOOKING_ENDPOINT) {
    fetch(BOOKING_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(submittedData),
    }).catch(() => {});
  }

  const ev = EVENTS[data.event];
  summaryEl.textContent =
    `${data.name.trim()}, ждём вас: ${ev.label}, группа ${ev.group}, 19:00, бар Gazgaz. ` +
    `Мы подтвердим бронь по контакту: ${contact}.`;

  form.hidden = true;
  payNote.hidden = true;
  doneView.hidden = false;
  payBtn.focus({ preventScroll: true });
});

payBtn.addEventListener("click", () => {
  const link = submittedData && PAYMENT_LINKS[submittedData.event];
  if (link) {
    window.location.href = link;
  } else {
    payNote.hidden = false;
  }
});
