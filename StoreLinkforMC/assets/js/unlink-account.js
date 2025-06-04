document.addEventListener("DOMContentLoaded", () => {
    const button = document.getElementById("storelinkformc-unlink-button");
    if (button) {
        button.addEventListener("click", () => {
            if (!confirm("Are you sure you want to unlink your Minecraft account?")) return;

            fetch(storelinkformc_vars.ajax_url, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: `action=storelinkformc_unlink_account&security=${storelinkformc_vars.nonce}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert("Minecraft account unlinked.");
                    location.reload();
                } else {
                    alert("Failed to unlink account: " + (data.error || "Unknown error"));
                }
            })
            .catch(() => {
                alert("Network or server error. Please try again later.");
            });
        });
    }
});
