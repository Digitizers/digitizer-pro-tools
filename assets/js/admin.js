(function ($) {
    'use strict';

    $(function () {
        if ($.fn.wpColorPicker) {
            $('.dpt-color').wpColorPicker();
        }

        var mediaFrame = null;

        $(document).on('click', '.dpt-image-select', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('.dpt-image-picker');

            mediaFrame = wp.media({
                title: 'Select background image',
                button: { text: 'Use this image' },
                multiple: false,
                library: { type: 'image' }
            });

            mediaFrame.on('select', function () {
                var att = mediaFrame.state().get('selection').first().toJSON();
                $wrap.find('input[name="dpt_cb[bg_image_url]"]').val(att.url);
                $wrap.find('input[name="dpt_cb[bg_image_id]"]').val(att.id);
                $wrap.find('.dpt-image-preview').html('<img src="' + att.url + '" alt="" />');
                $wrap.find('.dpt-image-remove').show();
            });

            mediaFrame.open();
        });

        $(document).on('click', '.dpt-image-remove', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('.dpt-image-picker');
            $wrap.find('input[name="dpt_cb[bg_image_url]"]').val('');
            $wrap.find('input[name="dpt_cb[bg_image_id]"]').val('');
            $wrap.find('.dpt-image-preview').empty();
            $(this).hide();
        });
    });
})(jQuery);
