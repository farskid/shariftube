jQuery(function ($) {
    if ($("#file-progress").length) {
        var last_index = -1, current_index = 0, progress = $("#file-progress").attr('rel');
        var ProgressBar = setInterval(function () {
            $.ajax({
                url: '/link/',
                dataType: 'json',
                type: 'post',
                data: 'progress=' + progress + '&index=' + current_index,
                success: function (data) {
                    if (data.index > last_index) {
                        $("#file-progress .bar").css('width', data.percentage + '%');
                        $("#file-progress .info").html(data.message);
                        last_index = data.index;
                        if (data.completed) {
                            clearInterval(ProgressBar);
                        }
                    }
                }
            });
            current_index++;
        }, 2000);
    }
    $("form.params").submit(function (e) {
        e.preventDefault();
        var link = $(this).attr('action');
        var list = [];
        $('input[name],select[name]', this).each(function (index) {
            if ($(this).prop('tagName') == 'SELECT') {
                var val = $('option:selected', this).val();
            } else {
                var val = $(this).val();
            }
            if ($(this).attr('data-encrypt') == 'base64') {
                val = Base64.encode(val);
            } else if ($(this).attr('data-encrypt') == 'vinixhash') {
                val = VinixHash.encode(val);
            }
            if (typeof $(this).attr('data-index') != 'undefined') {
                index = $(this).attr('data-index');
            }
            list[index] = encodeURIComponent(val);
        });
        var targetList = [], index = 0;
        for (var i in list) {
            if (typeof list[i] != 'undefined') {
                targetList[index] = list[i];
                index++;
            }
        }
        link = link + targetList.join('/');
        var a = $('<a>');
        $(a).attr('href', link);
        $(a).css('display', 'none');
        $('body').append(a);
        $(a).get(0).click();
        return false;
    });
    $('a.disabled').click(function (e) {
        e.preventDefault();
    });
});


var VinixHash = {
    _keyStr: "abcdefghijklmnopqrstuvwxyz", encode: function (e) {
        var t = "";
        var c, s, n, l = this._keyStr.length;
        var f = 0;
        e = this._utf8_encode(e);
        while (f < e.length) {
            c = e.charCodeAt(f++);
            s = c % l;
            n = parseInt(c / l);
            if (isNaN(s)) {
                s = 0;
            }
            t = t + this._keyStr.charAt(s) + n;
        }
        return t
    }, decode: function (e) {
        var t = "";
        var c, s, n, l = this._keyStr.length;
        var f = 0;
        e = e.replace(/[^A-Za-z0-9]/g, "");
        while (f < e.length) {
            s = this._keyStr.indexOf(e.charAt(f++));
            n = parseInt(e.charAt(f++));
            if (isNaN(n)) {
                n = 0
            }
            c = (n * l) + s;
            t = t + String.fromCharCode(c);
        }
        t = Base64._utf8_decode(t);
        return t
    }, _utf8_encode: function (e) {
        e = e.replace(/\r\n/g, "\n");
        var t = "";
        for (var n = 0; n < e.length; n++) {
            var r = e.charCodeAt(n);
            if (r < 128) {
                t += String.fromCharCode(r)
            } else if (r > 127 && r < 2048) {
                t += String.fromCharCode(r >> 6 | 192);
                t += String.fromCharCode(r & 63 | 128)
            } else {
                t += String.fromCharCode(r >> 12 | 224);
                t += String.fromCharCode(r >> 6 & 63 | 128);
                t += String.fromCharCode(r & 63 | 128)
            }
        }
        return t
    }, _utf8_decode: function (e) {
        var t = "";
        var n = 0;
        var r = c1 = c2 = 0;
        while (n < e.length) {
            r = e.charCodeAt(n);
            if (r < 128) {
                t += String.fromCharCode(r);
                n++
            } else if (r > 191 && r < 224) {
                c2 = e.charCodeAt(n + 1);
                t += String.fromCharCode((r & 31) << 6 | c2 & 63);
                n += 2
            } else {
                c2 = e.charCodeAt(n + 1);
                c3 = e.charCodeAt(n + 2);
                t += String.fromCharCode((r & 15) << 12 | (c2 & 63) << 6 | c3 & 63);
                n += 3
            }
        }
        return t
    }
};

// Create Base64 Object
var Base64 = {
    _keyStr: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=", encode: function (e) {
        var t = "";
        var n, r, i, s, o, u, a;
        var f = 0;
        e = Base64._utf8_encode(e);
        while (f < e.length) {
            n = e.charCodeAt(f++);
            r = e.charCodeAt(f++);
            i = e.charCodeAt(f++);
            s = n >> 2;
            o = (n & 3) << 4 | r >> 4;
            u = (r & 15) << 2 | i >> 6;
            a = i & 63;
            if (isNaN(r)) {
                u = a = 64
            } else if (isNaN(i)) {
                a = 64
            }
            t = t + this._keyStr.charAt(s) + this._keyStr.charAt(o) + this._keyStr.charAt(u) + this._keyStr.charAt(a)
        }
        return t
    }, decode: function (e) {
        var t = "";
        var n, r, i;
        var s, o, u, a;
        var f = 0;
        e = e.replace(/[^A-Za-z0-9\+\/\=]/g, "");
        while (f < e.length) {
            s = this._keyStr.indexOf(e.charAt(f++));
            o = this._keyStr.indexOf(e.charAt(f++));
            u = this._keyStr.indexOf(e.charAt(f++));
            a = this._keyStr.indexOf(e.charAt(f++));
            n = s << 2 | o >> 4;
            r = (o & 15) << 4 | u >> 2;
            i = (u & 3) << 6 | a;
            t = t + String.fromCharCode(n);
            if (u != 64) {
                t = t + String.fromCharCode(r)
            }
            if (a != 64) {
                t = t + String.fromCharCode(i)
            }
        }
        t = Base64._utf8_decode(t);
        return t
    }, _utf8_encode: function (e) {
        e = e.replace(/\r\n/g, "\n");
        var t = "";
        for (var n = 0; n < e.length; n++) {
            var r = e.charCodeAt(n);
            if (r < 128) {
                t += String.fromCharCode(r)
            } else if (r > 127 && r < 2048) {
                t += String.fromCharCode(r >> 6 | 192);
                t += String.fromCharCode(r & 63 | 128)
            } else {
                t += String.fromCharCode(r >> 12 | 224);
                t += String.fromCharCode(r >> 6 & 63 | 128);
                t += String.fromCharCode(r & 63 | 128)
            }
        }
        return t
    }, _utf8_decode: function (e) {
        var t = "";
        var n = 0;
        var r = c1 = c2 = 0;
        while (n < e.length) {
            r = e.charCodeAt(n);
            if (r < 128) {
                t += String.fromCharCode(r);
                n++
            } else if (r > 191 && r < 224) {
                c2 = e.charCodeAt(n + 1);
                t += String.fromCharCode((r & 31) << 6 | c2 & 63);
                n += 2
            } else {
                c2 = e.charCodeAt(n + 1);
                c3 = e.charCodeAt(n + 2);
                t += String.fromCharCode((r & 15) << 12 | (c2 & 63) << 6 | c3 & 63);
                n += 3
            }
        }
        return t
    }
};