<div class="main-section">
    <div>
        <div class="clearfix">
            <div class="col-xs-6 col-xs-offset-3">
                <div class="alert-lg">{{ flash.output() }}</div>
            </div>
        </div>
    </div>
    {% if file_id > 0 %}
    <div id="file-progress" rel="{{ file_id }}" class="progress-bar">
        <div class="bar" style="width: 0%;"></div>
        <div class="info">
            در انتظار دریافت فایل
        </div>
    </div>
    {% endif %}
    <div class="row">
        <div class="col-xs-12 searchbar-item">
            <form class="params search-bar" action="{{ url.get(['for':'link']) }}" method="get">
                <div class="input-group form-group">
                    <input id="link" data-encrypt="vinixhash" type="text" class="form-control input-lg" name="link" placeholder="لینک ویدیو مورد نظر را واردنمایید" value="{{ link|e }}">
                    <span class="input-group-btn">
                        <button class="btn btn-search">
                            <i class="fa fa-search"></i>
                        </button>
                    </span>
                </div>
            </form>
        </div>
    </div>
    {% if records|length %}
    <div class="row">
        <h3>{{ label|e }}</h3>
        <table class="table table-striped table-hover">
            <tr>
                <th>کیفیت</th>
                <th>نوع</th>
                <th>سه بعدی</th>
                <th>ظرفیت</th>
                <th>لینک</th>
            </tr>
        {% for record in records %}
            <tr>
                <td>{{ record.quality }}</td>
                <td>{{ record.type }}</td>
                <td>{% if record.is_3d %}بلی{% else %}خیر{% endif %}</td>
                <td>{{ number_format(record.size/1024/1024, 2) }} مگابایت</td>
                <td>
                    <form method="post" action="{{ url.get(['for':'link', 'link':dispatcher.getParam('link')])|e }}">
                        <input type="hidden" name="params" value="{{ record.params|e }}">
                        <input class="btn btn-primary btn-sm" type="submit" name="get" value="دریافت">
                    </form>
                </td>
            </tr>
        {% endfor %}
        </table>
    </div>
    {% endif %}
</div>