document.addEventListener('DOMContentLoaded', () => {
  const toggleBtn = document.querySelector('#menuToggle');
  const mobileMenu = document.querySelector('#mobileMenu');
  if (toggleBtn && mobileMenu) {
    toggleBtn.addEventListener('click', () => mobileMenu.classList.toggle('open'));
  }

  // Simple auto-refresh for chat messages (long-poll fallback)
  const chatBox = document.querySelector('#chatBox');
  const chatDebateId = chatBox ? chatBox.dataset.debateId : null;
  if (chatDebateId) {
    const loadChat = () => {
      fetch(`/debates/chat_fetch.php?debate_id=${chatDebateId}`)
        .then(r => r.text())
        .then(html => { chatBox.innerHTML = html; })
        .catch(() => {});
    };
    loadChat();
    setInterval(loadChat, 3000);
  }
});
