/* ============================================================
   CONFIG — edit these before going live
   ============================================================ */

/* Where to send booking requests as JSON. The PHP handler in the site
   root forwards them to amoCRM (contact + lead + note); config lives in
   amo-config.php. Leave empty to skip network submission — the form then
   works as a stub: the guest just sees the "we'll contact you"
   confirmation. */
const BOOKING_ENDPOINT = "/form-handler.php";

/* Шаг оплаты: pay.php считает подпись Robokassa по серверной цене и
   отправляет гостя на платёжную страницу. Сумма и услуга в браузер не
   попадают. Пустая строка — оплата отключена, гость увидит экран
   «заявка принята» и с ним свяжется администратор.

   ВРЕМЕННО ОТКЛЮЧЕНО — ждём подтверждения магазина в Robokassa.
   Чтобы включить оплату обратно:
     1) вернуть сюда "/pay.php";
     2) заполнить robokassa-config.php на сервере;
     3) вернуть «платёжные» тексты формы — см. коммит
        «Временно: заявка вместо оплаты до подтверждения Robokassa»,
        он меняет только index.html и soglasie.html. */
const PAYMENT_ENDPOINT = "";

/* Честный статус мест по каждому вечеру — обновляется руками, отдельно
   для девушек (f) и мужчин (m).
   Допустимые значения: "open" | "few" | "ask" | "closed" | null (строку не трогаем).
   Никаких числовых счётчиков: не показываем цифры, которые не можем держать точными. */
const AVAILABILITY = {
  aug21: { f: "ask", m: "open" },
  aug22: { f: "ask", m: "open" },
};

/* Текст в строке «Места» карточки вечера */
const AVAILABILITY_LABELS = {
  open: "места есть",
  few: "мест мало",
  ask: "уточняйте наличие мест",
  closed: "запись закрыта",
};

/* time: "" — время вечера ещё не объявлено, в подтверждении его не показываем */
const EVENTS = {
  aug21: { label: "пятница, 21 августа", group: "группа 22–35 лет", time: "19:00" },
  aug22: { label: "суббота, 22 августа", group: "группа 22–35 лет, вечер для тех, кто занимается спортом", time: "19:00" },
};

const TICKET_PRICES = { m: "2 300 ₽", f: "2 300 ₽" };

const CONTACT_METHODS = {
  phone: { placeholder: "+7 900 000-00-00", type: "tel", inputmode: "tel", autocomplete: "tel" },
  telegram: { placeholder: "@username", type: "text", inputmode: "text", autocomplete: "off" },
  max: { placeholder: "+7 900 000-00-00 или @username", type: "text", inputmode: "text", autocomplete: "off" },
};

/* ============================================================
   Статус мест в карточках расписания
   ============================================================ */

document.querySelectorAll("[data-availability]").forEach((el) => {
  const status = AVAILABILITY[el.dataset.availability];
  if (!status) return;

  el.textContent = "";
  [["девушки", status.f], ["мужчины", status.m]].forEach(([who, st]) => {
    const label = AVAILABILITY_LABELS[st];
    if (!label) return;
    const line = document.createElement("span");
    line.className = "event__status-line event__status-line--" + st;
    line.textContent = who + ": " + label;
    el.append(line);
  });

  if (status.f !== "closed" || status.m !== "closed") return;

  /* Запись закрыта для всех — кнопку в карточке выключаем, чтобы не собирать
     заявки на собранную группу */
  const btn = el.closest(".event")?.querySelector("[data-open-booking]");
  if (btn) {
    btn.disabled = true;
    btn.textContent = "Запись закрыта";
  }
});

/* ============================================================
   Шапка: линейка появляется только после прокрутки
   ============================================================ */

const nav = document.querySelector(".nav");
const stickyCta = document.getElementById("sticky-cta");

function onScroll() {
  const y = window.scrollY;
  nav.classList.toggle("is-scrolled", y > 12);
  /* Панель записи внизу экрана — после того, как первый экран прокручен:
     пока видна кнопка в первом экране, дублировать её незачем */
  if (stickyCta) stickyCta.classList.toggle("is-visible", y > 420);
}

onScroll();
window.addEventListener("scroll", onScroll, { passive: true });

/* ============================================================
   Cookie notice — уведомление без кнопок согласия (см. cookies.html);
   закрытие запоминается в localStorage
   ============================================================ */

const cookieNote = document.getElementById("cookie-note");
if (cookieNote) {
  let cookieNoteClosed = false;
  try {
    cookieNoteClosed = localStorage.getItem("cookieNoteClosed") === "1";
  } catch {}
  if (!cookieNoteClosed) {
    cookieNote.hidden = false;
    requestAnimationFrame(() => cookieNote.classList.add("is-visible"));
    cookieNote.querySelector(".cookie-note__close").addEventListener("click", () => {
      cookieNote.classList.remove("is-visible");
      setTimeout(() => { cookieNote.hidden = true; }, 320);
      try {
        localStorage.setItem("cookieNoteClosed", "1");
      } catch {}
    });
  }
}

/* ============================================================
   Фотографии — тап открывает кадр во весь экран
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

document.querySelectorAll(".photo").forEach((item) => {
  const media = item.querySelector("img");
  if (!media) return;
  item.classList.add("photo--clickable");
  item.setAttribute("role", "button");
  item.setAttribute("tabindex", "0");
  item.setAttribute("aria-label", "Открыть фото на весь экран");
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
  reduced ? finish() : setTimeout(finish, 300);

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

function getMetrikaClientId() {
  return new Promise((resolve) => {
    if (typeof window.ym !== "function") {
      resolve("");
      return;
    }

    let settled = false;
    const finish = (value = "") => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      const clientId = String(value).trim();
      resolve(/^\d{5,32}$/.test(clientId) ? clientId : "");
    };
    const timer = setTimeout(finish, 800);

    try {
      window.ym(110737561, "getClientID", finish);
    } catch {
      finish();
    }
  });
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
let redirectingToPayment = false;

function showSubmitError(message) {
  const el = document.querySelector('[data-error-for="submit"]');
  if (!el) return;
  el.textContent = message;
  el.hidden = false;
}

function showDoneView(data) {
  const ev = EVENTS[data.event];
  summaryEl.textContent =
    `${data.name.trim()}, вы выбрали: ${ev.label}, ${ev.group}, ${ev.time ? ev.time + ", " : ""}бар GasGas. ` +
    `Участие — ${currentPrice()}` +
    (ev.time ? "" : ". Точное время вечера подтвердит администратор");

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
    metrikaClientId: "",
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
    submittedData.metrikaClientId = await getMetrikaClientId();

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

    /* Сделка создана — сразу отправляем гостя на оплату Robokassa:
       место закрепляется только после успешного платежа. Кнопку не
       разблокируем, чтобы по дороге не отправить заявку второй раз */
    const leadId = parseInt(result.leadId, 10);
    if (PAYMENT_ENDPOINT && leadId > 0) {
      redirectingToPayment = true;
      submitBtn.textContent = "Переходим к оплате…";
      const payParams = new URLSearchParams({
        lead: String(leadId),
        event: submittedData.event,
        gender: submittedData.gender,
      });
      window.location.assign(`${PAYMENT_ENDPOINT}?${payParams}`);
      return;
    }

    /* Сделка в amoCRM не создалась (заявка ушла в мессенджеры запасным
       каналом) — оплату не открываем, с гостем свяжется администратор */
    showDoneView(data);
    form.reset();
    applyContactMethod("phone");
  } catch {
    showSubmitError("Не удалось отправить заявку — проверьте связь и попробуйте ещё раз");
  } finally {
    /* Во время перехода на Robokassa страница ещё жива: не возвращаем
       кнопку в исходное состояние, иначе заявку можно отправить дважды */
    if (!redirectingToPayment) {
      submitting = false;
      submitBtn.disabled = false;
      submitBtn.textContent = submitBtnLabel;
    }
  }
});
