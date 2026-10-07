(function ($) {
    "use strict";
    $(document).ready(function () {
        var msg = $("#msg").val();
        var exmsg = $("#exmsg").val();

        if (msg) {
            setTimeout(function () {
                toastr.options = {
                    closeButton: true,
                    progressBar: true,
                    showMethod: 'slideDown',
                    timeOut: 4000
                };
                toastr.success(msg, 'Success');
                
                // After showing the message, clear it from the DOM
                $("#msg").val(''); // Remove the message value from the input
                
            }, 1300);
        }

        if (exmsg) {
            setTimeout(function () {
                toastr.options = {
                    closeButton: true,
                    progressBar: true,
                    showMethod: 'slideDown',
                    timeOut: 4000
                };
                toastr.error(exmsg, 'Something Went Wrong');
                
                // After showing the exception message, clear it from the DOM
                $("#exmsg").val(''); // Remove the exception message value from the input
            }, 1300);
        }
    });
}(jQuery));
