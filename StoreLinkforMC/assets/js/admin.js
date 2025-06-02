document.addEventListener('DOMContentLoaded', function () {
    const tokenField = document.getElementById('api-token-field');
    if (tokenField) {
        tokenField.addEventListener('click', function () {
            navigator.clipboard.writeText(tokenField.value).then(() => {
                alert('Token copied to clipboard!');
            }).catch(() => {
                alert('Failed to copy token.');
            });
        });
    }
});
