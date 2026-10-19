(function ($) {
    'use strict';

    $(function () {
        // Each switch's text is its row's heading, not its own label: name it
        // by that heading, or a screen reader announces an unlabeled checkbox.
        $('.dpt-switch input[type="checkbox"]').each(function (i) {
            var $th = $(this).closest('tr').children('th').first();
            if (!$th.length || this.hasAttribute('aria-labelledby')) return;
            var id = $th.attr('id') || 'dpt-switch-label-' + i;
            $th.attr('id', id);
            this.setAttribute('aria-labelledby', id);
        });

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
