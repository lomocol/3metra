/* ============================================================
   CONFIG — edit these before going live
   ============================================================ */

/* Where to send booking requests as JSON. The PHP handler in the site
   root forwards them to amoCRM (contact + lead + note); config lives in
   amo-config.php. Leave empty to skip network submission — the form then
   works as a stub: the guest just sees the "we'll contact you"
   confirmation. */
const BOOKING_ENDPOINT = "/form-handler.php";

/* Honest availability status per event — update by hand (or wire to a
   backend). Allowed values: "open" | "few" | "closed" | null (hides it).
   No numeric counters: never show numbers you can't keep accurate. */
const AVAILABILITY = {
  jul24: "open",
  jul25: "open",
  jul31: "open",
  aug1: "open",
};

const AVAILABILITY_LABELS = {
  open: { short: "Места есть", long: "Места на этот вечер есть — состав собираем вручную, поровну мужчин и женщин" },
  few: { short: "Мест мало", long: "Мест на этот вечер осталось мало — успейте забронировать" },
  closed: { short: "Запись закрыта", long: "Запись на этот вечер закрыта — группа собрана. Выберите другую дату" },
};

const EVENTS = {
  jul24: { label: "пятница, 24 июля", group: "основная группа" },
  jul25: { label: "суббота, 25 июля", group: "старшая группа" },
  jul31: { label: "пятница, 31 июля", group: "основная группа" },
  aug1: { label: "суббота, 1 августа", group: "старшая группа" },
};

const TICKET_PRICES = { m: "2 000 ₽", f: "2 300 ₽" };

const CONTACT_METHODS = {
  phone: { placeholder: "+7 900 000-00-00", type: "tel", inputmode: "tel", autocomplete: "tel" },
  telegram: { placeholder: "@username", type: "text", inputmode: "text", autocomplete: "off" },
  max: { placeholder: "+7 900 000-00-00 или @username", type: "text", inputmode: "text", autocomplete: "off" },
};

/* ============================================================
   Availability statuses on event cards
   ============================================================ */

document.querySelectorAll("[data-availability]").forEach((el) => {
  const status = AVAILABILITY[el.dataset.availability];
  const label = AVAILABILITY_LABELS[status];
  if (!label) return;
  el.textContent = label.short;
  el.hidden = false;
  if (status === "closed") {
    el.classList.add("event-card__status--closed");
    const btn = el.closest(".event-card, .next-card")?.querySelector("[data-open-booking]");
    if (btn) {
      btn.disabled = true;
      btn.textContent = "Запись закрыта";
    }
  }
});

document.querySelectorAll("[data-availability-long]").forEach((el) => {
  const label = AVAILABILITY_LABELS[AVAILABILITY[el.dataset.availabilityLong]];
  if (!label) return;
  el.textContent = label.long;
  el.hidden = false;
});

/* ============================================================
   Nav background on scroll
   ============================================================ */

const nav = document.querySelector(".nav");
const mobileCta = document.getElementById("mobile-cta");
const onScrollNav = () => {
  nav.classList.toggle("is-scrolled", window.scrollY > 12);
  /* Sticky CTA appears after the visitor scrolls past the hero */
  if (mobileCta) {
    const show = window.scrollY > window.innerHeight * 0.7;
    mobileCta.classList.toggle("is-visible", show);
    mobileCta.setAttribute("aria-hidden", show ? "false" : "true");
  }
};
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
   Gallery lightbox
   ============================================================ */

const lightbox = document.getElementById("lightbox");
const lightboxBody = lightbox.querySelector(".lightbox__body");
const lightboxClose = lightbox.querySelector(".lightbox__close");
let lightboxLastFocused = null;

function openLightbox(media) {
  const clone = media.cloneNode(true);
  clone.removeAttribute("width");
  clone.removeAttribute("height");
  clone.removeAttribute("loading");
  lightboxBody.replaceChildren(clone);

  lightboxLastFocused = document.activeElement;
  lightbox.hidden = false;
  document.body.style.overflow = "hidden";

  requestAnimationFrame(() => {
    lightbox.classList.add("is-open");
    lightboxClose.focus({ preventScroll: true });
  });

  if (clone.tagName === "VIDEO") {
    clone.muted = true;
    clone.loop = true;
    clone.setAttribute("playsinline", "");
    clone.play?.().catch(() => {});
  }
}

function closeLightbox() {
  lightbox.classList.remove("is-open");
  document.body.style.overflow = "";

  const finish = () => {
    lightbox.hidden = true;
    lightboxBody.replaceChildren();
  };
  const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  reduced ? finish() : setTimeout(finish, 250);

  lightboxLastFocused?.focus({ preventScroll: true });
}

document.querySelectorAll(".gallery__item:not(.gallery__item--placeholder)").forEach((item) => {
  const media = item.querySelector("img, video");
  if (!media) return;
  item.classList.add("gallery__item--clickable");
  item.setAttribute("role", "button");
  item.setAttribute("tabindex", "0");
  item.setAttribute("aria-label", "Открыть на весь экран");
  item.addEventListener("click", () => openLightbox(media));
  item.addEventListener("keydown", (e) => {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      openLightbox(media);
    }
  });
});

lightboxClose.addEventListener("click", closeLightbox);
lightbox.addEventListener("click", (e) => {
  if (e.target === lightbox || e.target === lightboxBody) closeLightbox();
});
document.addEventListener("keydown", (e) => {
  if (!lightbox.hidden && e.key === "Escape") closeLightbox();
});

/* ============================================================
   Booking modal
   ============================================================ */

const modal = document.getElementById("booking-modal");
const panel = modal.querySelector(".modal__panel");
const form = document.getElementById("booking-form");
const doneView = document.getElementById("booking-done");
const summaryEl = document.getElementById("booking-summary");
const contactInput = document.getElementById("booking-contact");

let lastFocused = null;
let submittedData = null;

function openModal(eventId) {
  lastFocused = document.activeElement;

  form.hidden = false;
  doneView.hidden = true;
  clearErrors();

  const preset = eventId && EVENTS[eventId] ? eventId : Object.keys(EVENTS)[0];
  const radio = form.querySelector(`input[name="event"][value="${preset}"]`);
  if (radio) radio.checked = true;

  modal.hidden = false;
  document.body.style.overflow = "hidden";

  requestAnimationFrame(() => {
    modal.classList.add("is-open");
    panel.querySelector("input, button")?.focus({ preventScroll: true });
  });
}

function closeModal() {
  modal.classList.remove("is-open");
  document.body.style.overflow = "";

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

/* Ticket price for the selected gender — shown in the confirmation summary */

function currentPrice() {
  const g = form.querySelector('input[name="gender"]:checked');
  return TICKET_PRICES[g ? g.value : "m"];
}

/* Contact method switches the input's keyboard and placeholder */

function applyContactMethod(value) {
  const cfg = CONTACT_METHODS[value];
  contactInput.type = cfg.type;
  contactInput.placeholder = cfg.placeholder;
  contactInput.setAttribute("inputmode", cfg.inputmode);
  contactInput.setAttribute("autocomplete", cfg.autocomplete);
}

form.querySelectorAll('input[name="method"]').forEach((radio) =>
  radio.addEventListener("change", () => {
    applyContactMethod(radio.value);
    contactInput.value = "";
    hideError("contact");
  })
);

/* Page URL, referrer and UTM params — attached to the booking payload
   so the organizer sees where the request came from */

function collectTracking() {
  const params = new URLSearchParams(window.location.search);
  const tracking = {
    page: window.location.href,
    referrer: document.referrer || "",
  };
  for (const key of ["utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term"]) {
    tracking[key] = params.get(key) || "";
  }
  return tracking;
}

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

const submitBtn = form.querySelector(".booking__submit");
const submitBtnLabel = submitBtn.textContent;
let submitting = false;
let redirecting = false;

function showSubmitError(message) {
  const el = document.querySelector('[data-error-for="submit"]');
  if (!el) return;
  el.textContent = message;
  el.hidden = false;
}

function showDoneView(data) {
  const ev = EVENTS[data.event];
  summaryEl.textContent =
    `${data.name.trim()}, вы выбрали: ${ev.label}, ${ev.group}, 19:00, бар-ресторан GasGas. ` +
    `Билет — ${currentPrice()}`;

  form.hidden = true;
  doneView.hidden = false;
  doneView.querySelector("button")?.focus({ preventScroll: true });
}

form.addEventListener("submit", async (e) => {
  e.preventDefault();
  if (submitting) return;
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

  const age = parseInt(data.age, 10);
  if (!age || age < 18 || age > 99) {
    showError("age");
    firstInvalid = firstInvalid || form.querySelector("#booking-age");
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
    age,
    gender: data.gender || "m",
    method: data.method,
    contact,
    consent: true,
    website: data.website || "",
    ...collectTracking(),
    submittedAt: new Date().toISOString(),
  };

  if (!BOOKING_ENDPOINT) {
    showDoneView(data);
    return;
  }

  submitting = true;
  submitBtn.disabled = true;
  submitBtn.textContent = "Отправляем…";

  try {
    const res = await fetch(BOOKING_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(submittedData),
    });
    let result = null;
    try {
      result = await res.json();
    } catch {}

    if (!res.ok || !result || result.success !== true) {
      showSubmitError(
        result && result.message
          ? result.message
          : "Не удалось отправить заявку — попробуйте ещё раз"
      );
      return;
    }

    /* Цель Метрики — только когда сделка реально создана в amoCRM
       (honeypot-ответ приходит с leadCreated: false и цель не трогает) */
    if (result.leadCreated === true && typeof window.ym === "function") {
      window.ym(110737561, "reachGoal", "lead_success");
    }

    /* Сделка создана — ведём гостя на оплату PayAnyWay. pay.php сам
       определит цену по коду услуги, сумме из браузера сервер не верит */
    if (result.leadCreated === true && result.leadId) {
      redirecting = true;
      submitBtn.textContent = "Переходим к оплате…";
      const payParams = new URLSearchParams({
        lead: String(result.leadId),
        event: data.event,
        gender: data.gender || "m",
      });
      /* Небольшая пауза, чтобы Метрика успела отправить цель */
      setTimeout(() => {
        window.location.assign("pay.php?" + payParams.toString());
      }, 400);
      return;
    }

    /* Запасной путь (бот в honeypot или сервер без leadId) — прежний
       экран «заявка отправлена» */
    showDoneView(data);
    form.reset();
    applyContactMethod("phone");
  } catch {
    showSubmitError("Не удалось отправить заявку — проверьте связь и попробуйте ещё раз");
  } finally {
    if (!redirecting) {
      submitting = false;
      submitBtn.disabled = false;
      submitBtn.textContent = submitBtnLabel;
    }
  }
});
