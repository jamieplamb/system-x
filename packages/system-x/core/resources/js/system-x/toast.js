// A transient, body-mounted notification. No dependencies; auto-dismisses. Used for per-app
// handler-error isolation (the desktop survives; the toast tells the user their action failed).
export function showToast(message, { duration = 4000 } = {}) {
    const el = document.createElement('div');
    el.className = 'sx-toast sx-overlay'; // .sx-overlay carries the z-tier token
    el.setAttribute('role', 'status');
    el.textContent = message; // textContent -- never innerHTML
    document.body.appendChild(el);
    const kill = () => el.remove();
    setTimeout(kill, duration);
    return kill;
}
