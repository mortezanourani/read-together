document.querySelectorAll("[data-copy-text]").forEach((button) => {
    button.addEventListener("click", () => {
        const status = button.nextElementSibling;
        const text = button.dataset.copyText;
        const input = document.createElement("textarea");
        const previousFocus = document.activeElement;
        let copied = false;

        input.value = text;
        input.setAttribute("readonly", "");
        input.style.position = "fixed";
        input.style.top = "0";
        input.style.left = "-9999px";
        document.body.append(input);
        input.focus();
        input.select();

        try {
            copied = document.execCommand("copy");
        } catch (error) {
            console.error("Legacy clipboard copy failed:", error);
        } finally {
            input.remove();
            button.focus();
        }

        if (copied) {
            status.textContent = "پیوند دعوت کپی شد.";
            return;
        }

        if (previousFocus instanceof HTMLElement) {
            previousFocus.focus();
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                status.textContent = "پیوند دعوت کپی شد.";
            }).catch((error) => {
                console.error("Could not copy invitation link:", error);
                status.textContent = "کپی پیوند ممکن نشد. لطفاً آن را از پیوند دعوت زیر کپی کنید.";
            });
            return;
        }

        status.textContent = "کپی پیوند ممکن نشد. لطفاً آن را از پیوند دعوت زیر کپی کنید.";
    });
});

function formatSolarHijriDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/.exec(value);
    if (!match) {
        return value;
    }

    const [, year, month, day, hour, minute] = match;
    const date = new Date(Date.UTC(
        Number(year),
        Number(month) - 1,
        Number(day),
        hour === undefined ? 12 : Number(hour),
        hour === undefined ? 0 : Number(minute)
    ));
    const options = {
        calendar: "persian",
        day: "numeric",
        month: "long",
        timeZone: "UTC",
        year: "numeric"
    };
    if (hour !== undefined) {
        options.hour = "2-digit";
        options.minute = "2-digit";
    }

    return new Intl.DateTimeFormat("fa-IR-u-ca-persian", options).format(date);
}

document.querySelectorAll("[data-solar-date]").forEach((element) => {
    const value = element.dataset.solarDate;
    if (value) {
        element.textContent = formatSolarHijriDate(value);
    }
});

document.querySelectorAll("[data-solar-date-for]").forEach((output) => {
    const input = document.querySelector(output.dataset.solarDateFor);
    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    const updateDisplay = () => {
        output.textContent = input.value
            ? formatSolarHijriDate(input.value)
            : "تاریخی انتخاب نشده است";
    };
    input.addEventListener("input", updateDisplay);
    input.addEventListener("change", updateDisplay);
    updateDisplay();
});

if ("serviceWorker" in navigator) {
    const script = document.currentScript;
    const serviceWorkerUrl = new URL("../../sw.js", script.src);
    window.addEventListener("load", () => {
        navigator.serviceWorker.register(serviceWorkerUrl).catch((error) => {
            console.error("Service worker registration failed:", error);
        });
    });
}
