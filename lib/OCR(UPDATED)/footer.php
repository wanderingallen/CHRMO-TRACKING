<div class="fab-chat" id="fabChat">
      <i class="fas fa-comments"></i>
    </div>

    <div class="chat-container" id="chatContainer">
      <div class="chat-header">
        <span>Support Chat</span>
        <button id="closeChat">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="chat-body" id="chatBody">
        <div class="chat-message message-received">
          <div class="chat-message-content">Hello! How can I help you today?</div>
        </div>
      </div>
      <div class="chat-input">
        <input type="text" id="chatInput" placeholder="Type your message..." />
        <button id="chatSend">
          <i class="fas fa-paper-plane"></i>
        </button>
      </div>
    </div>

    <script>
        // Sidebar Toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const body = document.body;

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            body.classList.toggle('sidebar-collapsed');
        });

        // Dropdown functionality for Notifications and User Profile
        function toggleDropdown(elementId) {
            const dropdown = document.getElementById(elementId);
            // Close all other dropdowns before opening a new one
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== elementId) {
                    menu.classList.remove('show');
                }
            });
            document.querySelectorAll('.chart-filter').forEach(filter => filter.classList.remove('active')); // Close chart filters
            dropdown.classList.toggle('show');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', (event) => {
            if (!event.target.closest('.header .right-section')) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => menu.classList.remove('show'));
            }
            if (!event.target.closest('.chart-filter')) {
                document.querySelectorAll('.chart-filter').forEach(filter => filter.classList.remove('active'));
            }
            // Close chat if clicked outside and it's open, but not on FAB
            if (!event.target.closest('#chatContainer') && !event.target.closest('#fabChat') && chatContainer.style.display === 'flex') {
                chatContainer.style.display = 'none';
            }
        });

        // Prevent dropdowns from closing when clicking inside
        document.querySelectorAll('.dropdown-menu').forEach(menu => {
            menu.addEventListener('click', e => e.stopPropagation());
        });

        // Chart Filter Dropdown
        function toggleChartFilter(button) {
            const parent = button.closest('.chart-filter');
            parent.classList.toggle('active');
            // Close other chart filters
            document.querySelectorAll('.chart-filter').forEach(filter => {
                if (filter !== parent) {
                    filter.classList.remove('active');
                }
            });
            document.querySelectorAll('.dropdown-menu').forEach(menu => menu.classList.remove('show')); // Close other general dropdowns
        }

        // Chatbot functionality
        const fabChat = document.getElementById('fabChat');
        const chatContainer = document.getElementById('chatContainer');
        const closeChat = document.getElementById('closeChat');
        const chatInput = document.getElementById('chatInput');
        const chatSend = document.getElementById('chatSend');
        const chatBody = document.getElementById('chatBody');

        fabChat.addEventListener('click', () => {
            chatContainer.style.display = chatContainer.style.display === 'flex' ? 'none' : 'flex';
            if (chatContainer.style.display === 'flex') {
                chatBody.scrollTop = chatBody.scrollHeight; // Scroll to bottom on open
            }
        });

        closeChat.addEventListener('click', () => {
            chatContainer.style.display = 'none';
        });

        function sendMessage() {
            const message = chatInput.value.trim();
            if (!message) return;

            // Add sent message
            const messageElement = document.createElement('div');
            messageElement.className = 'chat-message message-sent';
            messageElement.innerHTML = `<div class="chat-message-content">${message}</div>`;
            chatBody.appendChild(messageElement);
            chatInput.value = '';
            chatBody.scrollTop = chatBody.scrollHeight;

            // Simulate a response after a short delay
            setTimeout(() => {
                const responseElement = document.createElement('div');
                responseElement.className = 'chat-message message-received';
                responseElement.innerHTML = `<div class="chat-message-content">Thank you for your message. Our support team will get back to you shortly.</div>`;
                chatBody.appendChild(responseElement);
                chatBody.scrollTop = chatBody.scrollHeight;
            }, 1000);
        }

        chatSend.addEventListener('click', sendMessage);
        chatInput.addEventListener('keypress', e => {
            if (e.key === 'Enter') sendMessage();
        });

        // Initialize charts (from dashboard.html)
        document.addEventListener('DOMContentLoaded', () => {
            const ctx1 = document.getElementById('documentsStatusChart').getContext('2d');
            new Chart(ctx1, {
                type: 'doughnut',
                data: {
                    labels: ['Pending', 'Completed', 'In Progress', 'Overdue'],
                    datasets: [{
                        data: [30, 45, 15, 10], // Sample data
                        backgroundColor: ['#fbbf24', '#22c55e', '#3b82f6', '#ef4444'], // yellow, green, blue, red
                        borderColor: 'white',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: 'var(--text-dark)'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed !== null) {
                                        label += context.parsed + '%';
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });

            const ctx2 = document.getElementById('dailyActivityChart').getContext('2d');
            new Chart(ctx2, {
                type: 'line',
                data: {
                    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    datasets: [{
                        label: 'Documents Processed',
                        data: [12, 19, 3, 5, 2, 3, 7], // Sample data
                        fill: true,
                        backgroundColor: 'rgba(0, 188, 212, 0.2)', // primary-light
                        borderColor: 'var(--primary)',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'var(--border)'
                            },
                            ticks: {
                                color: 'var(--text-light)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: 'var(--text-light)'
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>