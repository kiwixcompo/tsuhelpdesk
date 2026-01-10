// Auto-refresh complaints for dashboards
// Usage: autoRefreshComplaints({
//   container: '.list-group',
//   afterSelector: '.bg-light',
//   getLastId: function() { ... },
//   renderComplaint: function(complaint) { ... },
//   userId: ..., userRoleId: ...
// });
(function(window, $) {
    window.autoRefreshComplaints = function(options) {
        var container = $(options.container);
        var afterSelector = options.afterSelector || null;
        var getLastId = options.getLastId;
        var renderComplaint = options.renderComplaint;
        var userId = options.userId;
        var userRoleId = options.userRoleId;
        var interval = options.interval || 20000;
        var endpoint = options.endpoint || 'get_new_complaints.php';
        var extraParams = options.extraParams || {};

        function poll() {
            var lastId = getLastId();
            var params = Object.assign({}, extraParams, { last_id: lastId });
            $.get(endpoint, params, function(data) {
                if (data.complaints && data.complaints.length > 0) {
                    data.complaints.reverse().forEach(function(complaint) {
                        complaint.created_at_fmt = formatDate(complaint.created_at);
                        var html = renderComplaint(complaint, userId, userRoleId);
                        if (afterSelector) {
                            container.find(afterSelector).after(html);
                        } else {
                            container.prepend(html);
                        }
                    });
                    // Highlight new complaints
                    container.find('.new-complaint').addClass('bg-warning');
                    setTimeout(function() { container.find('.new-complaint').removeClass('bg-warning new-complaint'); }, 3000);
                }
            }, 'json');
        }
        function formatDate(dateStr) {
            var d = new Date(dateStr);
            return d.toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
        }
        setInterval(poll, interval);
    };
})(window, jQuery); 