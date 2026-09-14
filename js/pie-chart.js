/* Pie Chart — shared by sales.php and reports.php */
function renderPieChart(containerId, data, colors, currency) {
  var container = document.getElementById(containerId);
  if (!container) return;
  var total = data.reduce(function(s, d) { return s + d.value; }, 0);
  if (total === 0) {
    container.innerHTML = '<div class="empty-state" style="padding:20px">No payment data yet</div>';
    return;
  }

  var size = 180, cx = size / 2, cy = size / 2, r = 70, inner = 40;
  var svg = '<svg width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">';
  var startAngle = -90;

  data.forEach(function(d, i) {
    if (d.value <= 0) return;
    var pct = d.value / total;
    var angle = pct * 360;
    if (angle >= 360) angle = 359.99;
    var endAngle = startAngle + angle;
    var largeArc = angle > 180 ? 1 : 0;

    var x1 = cx + r * Math.cos(Math.PI * startAngle / 180);
    var y1 = cy + r * Math.sin(Math.PI * startAngle / 180);
    var x2 = cx + r * Math.cos(Math.PI * endAngle / 180);
    var y2 = cy + r * Math.sin(Math.PI * endAngle / 180);

    var pctStr = (pct * 100).toFixed(1);
    svg += '<path class="pie-slice" data-label="' + d.label + '" data-pct="' + pctStr + '%" data-val="' + currency + d.value.toFixed(2) + '" d="M' + cx + ',' + cy + ' L' + x1 + ',' + y1 + ' A' + r + ',' + r + ' 0 ' + largeArc + ',1 ' + x2 + ',' + y2 + ' Z" fill="' + colors[i] + '"/>';
    startAngle = endAngle;
  });
  svg += '<circle cx="' + cx + '" cy="' + cy + '" r="' + inner + '" style="fill:#161616"/>';
  svg += '</svg>';

  var legend = '<div class="pie-legend">';
  data.forEach(function(d, i) {
    var pct = ((d.value / total) * 100).toFixed(1);
    legend += '<div class="pie-legend-item">' +
      '<span class="pie-legend-dot" style="background:' + colors[i] + '"></span>' +
      '<span class="pie-legend-label">' + d.label + '</span>' +
      '<span class="pie-legend-value">' + currency + d.value.toFixed(2) + '</span>' +
      '<span class="pie-legend-pct">(' + pct + '%)</span>' +
    '</div>';
  });
  legend += '</div>';

  container.innerHTML = svg + legend;

  // Tooltip
  var tip = document.getElementById('pieTooltip');
  if (!tip) {
    tip = document.createElement('div');
    tip.id = 'pieTooltip';
    tip.className = 'pie-tooltip';
    tip.innerHTML = '<div class="pie-tooltip-name"></div><div class="pie-tooltip-val"></div>';
    document.body.appendChild(tip);
  }

  container.querySelectorAll('.pie-slice').forEach(function(slice) {
    slice.addEventListener('mouseenter', function() {
      tip.querySelector('.pie-tooltip-name').textContent = this.dataset.label;
      tip.querySelector('.pie-tooltip-val').textContent = this.dataset.val + ' (' + this.dataset.pct + ')';
      tip.classList.add('show');
    });
    slice.addEventListener('mousemove', function(e) {
      tip.style.left = (e.clientX + 14) + 'px';
      tip.style.top = (e.clientY - 10) + 'px';
    });
    slice.addEventListener('mouseleave', function() {
      tip.classList.remove('show');
    });
  });
}