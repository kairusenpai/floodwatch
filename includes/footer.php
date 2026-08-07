  </div><!-- end .content -->
  <div class="page-footer">
    © Capstone Project 2026 &nbsp;·&nbsp; FloodWatch &nbsp;·&nbsp; Brgy. Baliwagan, San Enrique, Negros Occidental
  </div>
</div><!-- end .main -->

<script>
function updateClock() {
  const now = new Date();
  document.getElementById('clock').textContent = now.toLocaleTimeString('en-PH', {hour:'numeric',minute:'2-digit',second:'2-digit',hour12:true});
  document.getElementById('live-date').textContent = now.toLocaleDateString('en-PH', {weekday:'short',year:'numeric',month:'short',day:'numeric'});
}
setInterval(updateClock, 1000);
updateClock();
</script>
</body>
</html>
