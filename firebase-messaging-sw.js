importScripts('https://www.gstatic.com/firebasejs/12.6.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/12.6.0/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: "AIzaSyB3QZlJM50peeGc126ZcmRrpJsVK3qEmxQ",
  authDomain: "chrmo-21269.firebaseapp.com",
  projectId: "chrmo-21269",
  storageBucket: "chrmo-21269.firebasestorage.app",
  messagingSenderId: "1037241739258",
  appId: "1:1037241739258:web:28ad395cae1cd9fb4be643",
  measurementId: "G-RVK37NKG1W"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage((payload) => {
  const title = (payload && payload.notification && payload.notification.title) ? payload.notification.title : 'Notification';
  const body = (payload && payload.notification && payload.notification.body) ? payload.notification.body : '';
  const data = (payload && payload.data) ? payload.data : {};

  self.registration.showNotification(title, {
    body,
    data,
  });
});
