<!DOCTYPE html>
<html class="no-js">
    <head>
        <meta name="robots" content="noindex, nofollow"/>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title>شریف تیوب - {{ title }}</title>
        <meta name="description" content="شریف تیوب، سرویسی برای استفاده آکادمیک و آموزشی با هدف آسان سازی ارتباط با شبکه های ویدیویی وب">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="{{ url.getBaseUri() }}css/shariftube1446995875764.min.css">
    </head>
    <body>
    	<noscript><div style="position:fixed;width:100%;height:100%;background-color:#000;color:#fff;z-index:1000000000"><h1 style="position:absolute;top:50%;left:50%;-webkit-transform:translate3d(-50%,-50%,0);transform:translate3d(-50%,-50%,0);text-align:center;">کاربر گرامی، جاوااسکریپت مرورگر شما غیرفعال است. برای استفاده از این سرویس لطفا جاوااسکریپت مرورگر خود را فعال نمایید.</h1></div></noscript>
        <div class="container-fluid">
            <!-- Header -->
            <div class="row header">
                <div class="col-xs-12">
                    {% if header %}
                        {{ partial("partials/header") }}
                    {% endif %}
                </div>
            </div>
            <!-- Main -->
            <div class="container main-container">
                {% if !header %}
                    {{ '<div class="row center-item">' }}
                {% else %}
                    {{ '<div class="row dashboard-state">' }}
                {% endif %}
                <div class="col-xs-12">
                    {{ content() }}
                </div>
                <!-- Closing the in condition DIV -->
                </div>
                <!-- Prominent Video -->
                {% if header and prominents %}
                <div class="main-section">
                    <div class="prominents clearfix">
                        <div class="prominent-wrapper">
                            <h4 class="text-center">برخی ویدئوهای دانلود شده کاربران</h4>
                            {% for prominent in prominents %}
                            <div class="clearfix prominent-item">
                                <div class="col-xs-2">
                                    <div><a download class="btn-table" href="{{ prominent.getFinalLink()|e }}">دانلود</a></div>
                                </div>
                                <div class="col-xs-3">
                                    <div class="prominent-volume text-en">{{ number_format(prominent.size/1024/1024, 2) }}MB</div>
                                </div>
                                <div class="col-xs-7">
                                    <div class="prominent-title text-en" data-toggle="tooltip" data-placement="top" title="{{ prominent.label|e }}">{{ prominent.short_label|e }}</div>
                                </div>
                            </div>
                            {% endfor %}
                        </div>
                    </div>
                </div>
                {% endif %}
            </div>
            <!-- Footer -->
	        <div class="footer row">
	            <div class="col-xs-12">
	                {% if header %}
	                {{ partial("partials/footer") }}
	                {% endif %}
	            </div>
	        </div>
        </div>
        <script defer src="{{ url.getBaseUri() }}js/shariftube1446995875764.min.js"></script>
    </body>
</html>