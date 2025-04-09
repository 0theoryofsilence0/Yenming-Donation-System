jQuery(document).ready(function($) {
    // Use event delegation for dynamic content
    $('#checkout-form').on('click', '.edit-donation', function() {
        var entryId = $(this).data('id');
        var row = $(this).closest('tr');
        
        $(this).hide();
        row.find('.delete-donation').hide();
        row.find('.save-donation').show();
        row.find('.cancel-donation').show();
        row.find('.donation-amount').hide();
        row.find('.edit-amount').show().focus();
    });

    // Cancel button handler
    $('#checkout-form').on('click', '.cancel-donation', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var row = $btn.closest('tr');
        var originalAmount = row.find('.donation-amount').text();
        
        // Reset to original state
        row.find('.edit-amount').val(originalAmount).hide();
        row.find('.donation-amount').show();
        row.find('.edit-donation').show();
        row.find('.delete-donation').show();
        row.find('.save-donation').hide();
        $btn.hide();
    });

    $('#checkout-form').on('click', '.save-donation', function(e) {
    e.preventDefault();
    var $btn = $(this);
    var row = $btn.closest('tr');
    var entryId = $btn.data('id');
    var newAmount = parseFloat(row.find('.edit-amount').val());

    if (isNaN(newAmount)) {
        alert('请输入有效的金额/Please enter a valid amount');
        return;
    }

    $btn.prop('disabled', true).text('处理中/Processing...');
    row.find('.cancel-donation').prop('disabled', true);
    
    $.ajax({
        type: 'POST',
        url: donation_ajax.ajax_url,
        data: {
            action: 'update_donation_amount',
            entry_id: entryId,
            amount: newAmount.toFixed(2),
            security: donation_ajax.nonce
        },
        dataType: 'json',
        success: function(response) {
            if (response && response.success && response.data && response.data.redirect) {
                window.location.href = response.data.redirect;
            } else {
                location.reload(); // Fallback refresh
            }
        },
        error: function(xhr, status, error) {
            console.error('Error:', error);
            location.reload(); // Fallback refresh on error
        }
    });
});

$('#checkout-form').on('click', '.delete-donation', function(e) {
    e.preventDefault();
    if (!confirm('确定要删除此捐款吗？/Are you sure you want to delete this donation?')) {
        return;
    }

    var $btn = $(this);
    var entryId = $btn.data('id');

    $btn.prop('disabled', true);
    
    $.ajax({
        type: 'POST',
        url: donation_ajax.ajax_url,
        data: {
            action: 'delete_donation',
            entry_id: entryId,
            security: donation_ajax.nonce
        },
        dataType: 'json',
        success: function(response) {
            if (response && response.success && response.data && response.data.redirect) {
                window.location.href = response.data.redirect;
            } else {
                location.reload(); // Fallback refresh
            }
        },
        error: function(xhr, status, error) {
            console.error('Error:', error);
            location.reload(); // Fallback refresh on error
        }
    });
});
});
    