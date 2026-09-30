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

if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
        navigator.serviceWorker.register("./sw.js").catch((error) => {
            console.error("Service worker registration failed:", error);
        });
    });
}
