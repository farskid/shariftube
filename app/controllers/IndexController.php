<?php
namespace Shariftube\Controllers;

use Phalcon\Http\Response;
use Phalcon\Mvc\Model\Resultset;
use Phalcon\Paginator\Adapter\Model as PaginatorModel;
use PHPHtmlParser\Dom;
use Shariftube\Models\Announcements;
use Shariftube\Models\Comments;
use Shariftube\Models\Files;
use Shariftube\Models\Incomes;
use Shariftube\Models\Logs;
use Shariftube\Models\Packages;
use Shariftube\Models\PasswordChanges;
use Shariftube\Models\Purchases;
use Shariftube\Models\ResetPasswords;
use Shariftube\Models\Servers;
use Shariftube\Models\TicketReplays;
use Shariftube\Models\Tickets;
use Shariftube\Models\Unsubscribes;
use Shariftube\Models\Users;
use Shariftube\Models\Videos;
use Shariftube\Models\Websites;

/**
 * Display the default index page.
 */
class IndexController extends ControllerBase
{
    public function initialize()
    {
        $posts = $this->request->getPost();
        if (($this->auth->getIdentity() && $this->auth->getIdentity()->role != 'Admin')
            || (!$this->auth->getIdentity() && !empty($posts))
        ) {
            $log = new Logs();
            if ($this->auth->getIdentity()) {
                $log->user_id = $this->auth->getIdentity()->getId();
            } else {
                $log->user_id = null;
            }
            $log->uri = urldecode($this->request->getURI());

            if (is_array($posts)) {
                foreach ($posts as $name => $value) {
                    if (preg_match('/pass/i', $name)) {
                        $posts[$name] = str_repeat('*', mb_strlen($value, 'UTF-8'));
                    }
                }
            }
            if (empty($posts)) {
                $log->posts = ' ';
            } else {
                $log->posts = mb_substr(var_export($posts, true), 0, 1024, 'UTF-8');
            }
            $log->save();
        }


        $this->view->header = true;
        $this->response->setHeader('Server', 'sharifwebserver/0.9');
        $date = new \jDateTime(true, true, 'Asia/Tehran');
        $this->view->date = $date;
        $this->view->admin = false;
        $this->view->suspended = false;
        if ($this->auth->getIdentity()) {
            if ($this->auth->getIdentity()->status == 'Suspended') {
                $this->flash->error('حساب کاربری شما معلق شده است. برای رفع این موضوع با پشتیبانی تماس بگیرید.');
                $this->view->suspended = true;
            }
            $this->view->admin = $admin = $this->auth->getIdentity()->role == 'Admin' ? true : false;
            $time = time();
            if ($this->auth->getIdentity()->role == 'Admin') {
                $tickets = Tickets::find([
                    'status IN ({status:array})',
                    'bind' => [
                        'status' => ['Open', 'Replay', 'InProgress'],
                    ],
                    'order' => 'modified_at DESC',
                ])->count();
                $this->view->users_count = Users::count();
                $this->view->purchases_amount = Purchases::sum([
                    'column' => 'amount',
                    'conditions' => "status = 'Success'",
                ]);
            } else {
                $tickets = Tickets::find([
                    'user_id = :user: AND status IN ({status:array})',
                    'bind' => [
                        'user' => $this->auth->getIdentity()->getId(),
                        'status' => ['Answered'],
                    ],
                    'order' => 'modified_at DESC',
                ])->count();
            }
            $this->view->open_tickets = $tickets;
            $this->view->announcements = Announcements::find([
                'order' => 'created_at DESC',
                'limit' => 4,
            ]);

            $this->view->referral_count = Users::findByReferralId($this->auth->getIdentity()->getId())->count();
            $this->view->total_incomes = Incomes::sum([
                'column' => 'amount',
                'conditions' => 'user_id = :user_id: AND deleted_at = 0',
                'bind' => [
                    'user_id' => $this->auth->getIdentity()->getId(),
                ],
            ]);

            $this->view->month_incomes = Incomes::sum([
                'column' => 'amount',
                'conditions' => 'user_id = :user_id: AND created_at BETWEEN :start: AND :end: AND deleted_at = 0',
                'bind' => [
                    'user_id' => $this->auth->getIdentity()->getId(),
                    'start' => $date->date('Y-m-d H:i:s',
                        $date->mktime(0, 0, 0, $date->date('n', $time, false), 1, $date->date('Y', $time, false)),
                        false, false),
                    'end' => $date->date('Y-m-d H:i:s',
                        $date->mktime(23, 59, 59, $date->date('n', $time, false), $date->date('t', $time, false),
                            $date->date('Y', $time, false)), false, false),
                ],
            ]);

            $files = Files::find([
                "status = 'Prominent' AND deleted_at = 0",
                'order' => 'created_at DESC',
                'limit' => 10,
            ]);
            $this->view->prominents = $prominents = array();
            if ($files) {
                foreach ($files as $file) {
                    $file->short_label = $file->label;
                    if (mb_strlen($file->short_label, 'UTF-8') > 40) {
                        $file->short_label = mb_substr($file->short_label, 0, 37, 'UTF-8') . ' ...';
                    }
                    $prominents[] = $file;
                }
            }
            $this->view->prominents = $prominents;
            unset($prominents, $files);
        }
    }
    public function indexAction()
    {
        if ($this->view->suspended) {
            $this->view->disable();
            $this->response->redirect(['for' => 'support']);
            return;
        }
        if (!$this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'login']);
            return;
        }

        $videos = Videos::getList($this->auth->getIdentity(), $this->config->channels->home_page, $this->config->channels->base);
        if (empty($videos)) {
            $this->view->disable();
            $this->response->redirect(['for' => 'get']);
            return;
        }
        $this->view->title = 'خانه';
        $this->view->videos = Videos::prepareVideos($videos);
    }

    public function clickAction()
    {
        $this->view->disable();
        if ($this->view->suspended) {
            $this->response->redirect(['for' => 'support']);
            return;
        }
        if (!$this->auth->getIdentity()) {
            $this->response->redirect(['for' => 'login']);
            return;
        }
        $video = Videos::findFirst([
            "id = :id:",
            'bind' => [
                'id' => $this->dispatcher->getParam('id'),
            ],
        ]);
        if (empty($video)) {
            $this->response->redirect(['for' => 'home']);
            return;
        }
        Videos::click($this->auth->getIdentity(), $video);
        $this->response->redirect(['for' => 'link', 'link' => $video->prepareUri()]);
    }

    public function commentAction()
    {
        $this->view->home_link = false;
        $this->view->comment_form = false;
        if (!$this->auth->getIdentity()) {
            $this->view->header = false;
            $this->view->home_link = true;
        }
        $this->view->title = 'ارسال نظر';
        $auth = '';
        if ($this->request->getPost('auth')) {
            $auth = $this->request->getPost('auth');
            $auth = @preg_replace('/[\x00]+/', '', $this->crypt->decryptBase64($auth));
        } else if ($this->dispatcher->getParam('auth')) {
            $auth = $this->dispatcher->getParam('auth');
            $auth = @preg_replace('/[\x00]+/', '', $this->crypt->decrypt(vinixhash_decode($auth)));
        } else if ($this->auth->getIdentity()) {
            $auth = $this->auth->getIdentity()->getId() . ',' . $this->auth->getIdentity()->email;
        }

        $this->view->auth = vinixhash_encode($this->crypt->encrypt($auth));

        $auth = explode(',', $auth);
        if (count($auth) != '2') {
            $this->flash->error('اطلاعات ارسال شده صحیح نمی باشند.');
            return;
        }
        $user = Users::findFirst([
            "id = :id: AND email = :email: AND deleted_at = 0 AND status != 'Suspended'",
            'bind' => [
                'id' => $auth[0],
                'email' => $auth[1],
            ],
        ]);
        if (!$user/* || !$this->security->checkHash(vinixhash_decode($auth[2]), $user->password)*/) {
            $this->flash->error('شما اجازه ارسال نظر ندارید.');
            return;
        }

        if ($this->request->getPost('comment')) {
            $content = $this->request->getPost('comment');
            if (strlen($content) < 3) {
                $this->view->link_form = true;
                $this->flash->error('لطفا متن نظر را کامل وارد نمایید.');
                return;
            }
            $comment = new Comments();
            $comment->user_id = $user->getId();
            $comment->content = $content;
            $comment->status = 'Waiting';
            if ($comment->save()) {
                $this->flash->success('با سپاس از زمانی که گذاشتید. نظر شما ثبت شد.');
            } else {
                $this->view->link_form = true;
                $this->flash->error('متاسفانه ثبت نظر شما با مشکل مواجه شد. لطفا دوباره تلاش نمایید.');
            }
        } else {
            $this->view->comment_form = true;
        }
    }

    public function videoAction()
    {
        if ($this->view->suspended) {
            $this->view->disable();
            $this->response->redirect(['for' => 'support']);
            return;
        }
        if (!$this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'login']);
            return;
        }
        $this->view->title = '';
        $id = $this->dispatcher->getParam('id');
        if (!$this->session->has($id)) {
            $this->flash->error('فایل مورد نظر شما در سیستم یافت نشد.1');
            return;
        }
        $video = $name = $this->session->get($id);
        $website = @json_decode($video['website']);
        if (!$website) {
            $this->flash->error('فایل مورد نظر شما در سیستم یافت نشد.2');
            return;
        }

        if (!$website || !class_exists('\\Shariftube\\Websites\\' . $website->name)) {
            $this->flash->error('فایل مورد نظر شما در سیستم یافت نشد.3');
            return;
        }
        $leecher = '\\Shariftube\\Websites\\' . $website->name;
        $leecher = new $leecher;

        $this->view->disable();
        $start = 0;
        $size = ceil($video['size'] / 10);
        if ($size > $this->config->application->trailer_limit) {
            $size = $this->config->application->trailer_limit;
        }
        $end = $size - 1;
        if (preg_match('/(?P<start>\d*)\s*\-\s*(?P<end>\d*)/', $this->request->getHeader('Range'), $match)) {
            if ($match['start'] < $end) {
                $start = $match['start'];
            }
            if ($match['end'] < $end) {
                $end = $match['end'];
            }
        }

        $this->response->setContentType('video/' . $video['type']);
        $result = $leecher->getTrailer($video['link'], $start, $end);
        if ($result === null) {
            $this->response->setStatusCode(404, 'Not Found');
        } else {
            $this->response->setContentType($result['head']['content_type']);
            $this->response->setHeader('Content-Disposition', "filename={$id}.{$video['type']}");
            $this->response->setHeader('Accept-Ranges', 'bytes');
            $this->response->setHeader('Content-Length', $size);
            if ($this->request->getHeader('Range')) {
                $this->response->setStatusCode(206, 'Partial Content');
            } else {
                $this->response->setStatusCode(200, 'Ok');
            }
        }

        $this->response->setContent($result['content']);
        $this->response->send();
    }

    public function playAction()
    {
        if ($this->view->suspended) {
            $this->view->disable();
            $this->response->redirect(['for' => 'support']);
            return;
        }
        if (!$this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'login']);
            return;
        }
        $this->view->title = 'پخش آنلاین ویدئو';
        $this->view->id = $id = $this->dispatcher->getParam('id');
        $file = Files::findFirst([
            "id = :id: AND user_id = :user: AND status IN ('Success', 'Prominent') AND deleted_at = 0",
            'bind' => [
                'id' => $id,
                'user' => $this->auth->getIdentity()->getId(),
            ],
        ]);
        $this->view->file = '';
        if (!$file) {
            $this->flash->error('فایل مورد نظر شما در سیستم یافت نشد.');
        } elseif (!in_array($file->type, ['webm', 'mp4', 'flv'])) {
            $this->flash->error('این ویدئو قابلیت پخش آنلاین ندارد.');
        } else {
            $this->view->file = $file->getFinalLink();
        }
    }

    public function getAction()
    {
        if ($this->view->suspended) {
            $this->view->disable();
            $this->response->redirect(['for' => 'support']);
            return;
        }
        if (!$this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'login']);
            return;
        }

        if ($this->request->getPost('progress')) {
            $this->view->disable();
            $response = array(
                'completed' => false,
                'success' => false,
                'index' => intval($this->request->getPost('index')),
                'percentage' => 0,
                'message' => '',
            );

            $file = Files::findFirst([
                'id = :id: AND deleted_at = 0',
                'bind' => [
                    'id' => $this->request->getPost('progress'),
                ],
            ]);
            if ($file) {
                $response['percentage'] = number_format(($file->fetched * 100) / $file->size, 2);
                switch ($file->status) {
                    case 'Waiting':
                        $response['message'] = 'در انتظار دریافت فایل';
                        break;
                    case 'InProgress':
                        $response['message'] = "{$response['percentage']}%";
                        break;
                    case 'Transferring':
                        $response['message'] = 'در حال آماده سازی فایل';
                        break;
                    case 'Failed':
                        $response['completed'] = true;
                        $response['message'] = 'دریافت فایل با مشکل روبرو شد';
                        break;
                    case 'Success':
                    case 'Prominent':
                        $response['completed'] = true;
                        $response['success'] = true;
                        $response['message'] = 'دریافت فایل به اتمام رسید. <a download href="' . $file->getFinalLink() . '">دانلود</a>';
                        break;
                }
            } else {
                $response['completed'] = true;
                $response['message'] = 'فایل یافت نشد';
            }


            $this->response->setContentType('application/json');
            $this->response->setContent(json_encode($response));
            $this->response->send();
            return;
        }

        $this->view->title = 'درخواست ویدئو';
        $this->view->link = $link = vinixhash_decode($this->dispatcher->getParam('link'));
        $this->view->suggestions = array();
        $this->view->records = array();
        $this->view->is_prominent = false;
        $this->view->label = '';
        $this->view->thumb = '';
        $this->view->file_id = 0;
        if ($link) {
            $website = Websites::findWebsite($link);
            if ($this->request->getPost('get')) {
                try {
                    $params = json_decode(preg_replace('/[\x00]+/', '',
                        $this->crypt->decryptBase64($this->request->getPost('params'))));
                } catch (\Exception $e) {
                    $this->flash->error('ویدئوی درخواستی نا معتبر می باشد.');
                    return;
                }
                $file = new Files();
                $file->user_id = $this->auth->getIdentity()->getId();
                $file->website_id = $website->getId();
                $file->type = $params->type;
                $file->name = md5($params->link) . '.' . $params->type;
                while (Files::find([
                    'deleted_at = 0 AND name = :name:',
                    'bind' => [
                        'name' => $file->name,
                    ],
                ])->count()) {
                    $file->name = md5(mt_rand() . uniqid()) . '.' . $file->type;
                }
                $file->label = $params->label;
                $file->size = $params->size;
                $file->uri = $link;
                $file->link = $params->link;
                $file->quality = $params->quality;
                $file->is_3d = $params->is_3d ? 'Yes' : 'No';
                $file->fetched = 0;
                $file->status = 'Waiting';
                try {
                    if (!$file->save()) {
                        $this->flash->error('فایل مورد نظر شما دانلود نشد. لطفا بعدا مجددا تلاش نمایید.');
                        return;
                    }
                } catch (\Exception $e) {
                    $messages = array(
                        'LOW_BALANCE' => sprintf('شما اعتبار کافی برای دریافت این ویدئو ندارید. می توانید از %s حجم بیشتری خرید نمایید.',
                            '<a href="' . $this->url->get(['for' => 'shop']) . '">اینجا</a>'),
                        'NO_SERVER' => 'دریافت این فایل فعلا امکان پذیر نیست. لطفا لحطاتی بعد مجددا تلاش نمایید.',
                    );
                    $message = $e->getMessage();
                    if (isset($messages[$message])) {
                        $message = $messages[$message];
                    }
                    $this->flash->error($message);
                    return;
                }
                $this->view->file_id = $file->getId();
                return;
            }
            if (!$website || !class_exists('\\Shariftube\\Websites\\' . $website->name)) {
                $this->flash->warning('آدرس وارد شده پشتیبانی نمی شود.');
                return;
            }
            $leecher = '\\Shariftube\\Websites\\' . $website->name;
            $leecher = new $leecher;
            $result = $leecher->getInfo($link);
            if ($result === null) {
                $this->flash->error('این ویدئو بخاطر قوانین کپی رایت قابل دریافت نمی باشد.');
            } elseif (empty($result) || empty($result['records'])) {
                $this->flash->error('هیچ ویدئویی در آدرس وارد شده یافت نشد.');
            } else {
                $this->view->is_prominent = Files::isProminent($link);
                foreach ($result['records'] as $index => $value) {
                    //if (in_array($value['type'], ['webm', 'mp4', 'flv'])) {
                    $hash = md5($value['link']) . '.' . $value['type'];
                    $this->session->set($hash, array_merge($value, ['website' => json_encode($website)]));
                    $value['trailer'] = $this->url->getStatic([
                        'for' => 'video',
                        'id' => $hash,
                    ]);
                    //} else {
                    //    $value['trailer'] = '';
                    //}
                    $value['params'] = $this->crypt->encryptBase64(json_encode($value));
                    $value['real_size'] = $this->view->is_prominent ? ceil($value['size']/2) : $value['size'];
                    $result['records'][$index] = (object)$value;
                }
                if (isset($result['suggestions']) && !empty($result['suggestions'])) {
                    foreach ($result['suggestions'] as $index => $value) {
                        $value['link'] = vinixhash_encode($value['link']);
                        $result['suggestions'][$index] = (object)$value;
                    }
                } else {
                    $result['suggestions'] = array();
                }
                $this->view->records = $result['records'];
                $this->view->suggestions = $result['suggestions'];
                $this->view->label = $result['label'];
                $this->view->thumb = $result['thumb'];
            }
            unset($result);
        }
    }

    public function searchAction()
    {
        if ($this->view->suspended) {
            $this->view->disable();
            $this->response->redirect(['for' => 'support']);
            return;
        }
        if (!$this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'login']);
            return;
        }
        $this->view->start = intval($this->dispatcher->getParam(0));
        $this->view->dur = $this->dispatcher->getParam(1);
        if (!$this->view->dur) {
            $this->view->dur = 'All';
        }
        $this->view->hq = $this->dispatcher->getParam(2);
        if (!$this->view->hq) {
            $this->view->hq = 'All';
        }
        $this->view->qdr = $this->dispatcher->getParam(3);
        if (!$this->view->qdr) {
            $this->view->qdr = 'All';
        }
        $this->view->website = $this->dispatcher->getParam(4);
        if (!$this->view->website) {
            $this->view->website = 'All';
        }
        $this->view->q = $this->dispatcher->getParam(5);
        $this->view->websites = Websites::find();

        $this->view->title = 'جست و جوی ویدئو';


        $this->view->records = $records = array();
        $this->view->captcha = false;
        $this->view->captcha_image = '';
        $this->view->hidden_items = array();
        $this->view->last_item = $this->view->start;
        $this->view->have_next = false;
        // if ($this->auth->getIdentity()->getId()!=1) {
        //     $this->flash->error('موتور جست و جو فعلا در دسترس نیست. لطفا بعدا مراجعه نمایید.');
        //     return;
        // }
        if ($this->view->q) {
            $websites = array();
            foreach ($this->view->websites as $item) {
                $list = array_filter(explode(',', $item->domains));
                foreach ($list as $domain) {
                    $websites[$domain] = $item->name;
                }
            }
            $link = 'https://www.google.com/search?hl=en&q=' . urlencode($this->view->q) . ($this->view->website != 'All' && in_array($this->view->website,
                    $websites) ? '+site%3A' . urlencode(array_search($this->view->website,
                        $websites)) : '') . '&num=50&tbm=vid';
            if ($this->view->start > 0) {
                $link .= '&start=' . intval($this->view->start);
            }
            $tbs = array();
            if ($this->view->dur != 'All') {
                $tbs['dur'] = $this->view->dur;
            }
            if ($this->view->hq != 'All') {
                $tbs['hq'] = $this->view->hq;
            }
            if ($this->view->qdr != 'All') {
                $tbs['qdr'] = $this->view->qdr;
            }
            if (!empty($tbs)) {
                $str = array();
                foreach ($tbs as $index => $value) {
                    $str[] = $index . ':' . urlencode($value);
                }
                $link .= '&tbs=' . implode(',', $str);
            }
            $link .= '&gws_rd=ssl';
            $ei = @file_get_contents(APP_DIR . '/cache/google.ei');
            if ($ei) {
                $link .= '&ei=' . urlencode($ei);
            }
            $header = array();
            $header['No-Cache'] = 1;
            if ($this->request->getPost('captcha')) {
                $params = $this->request->getPost('params');
                $action = preg_replace('/[\x00]+/', '', $this->crypt->decryptBase64($params['action']));
                $header['No-Cache'] = 1;
                $header['Referer'] = preg_replace('/[\x00]+/', '', $this->crypt->decryptBase64($params['referer']));
                $query = array();
                foreach ($params as $index => $value) {
                    if (!in_array($index, ['referer', 'action'])) {
                        $query[] = urlencode($index) . '=' . urlencode(preg_replace('/[\x00]+/', '',
                                $this->crypt->decryptBase64($value)));
                    }
                }
                $query[] = 'captcha=' . urlencode($this->request->getPost('code'));
                $query[] = 'submit=Submit';
                $link = "{$action}?" . implode('&', $query);
            }
            // $link = '';
//            $content = $this->curl->get($link, 5, 1, $header,false,1);
            // $content = $this->curl->get($link, 20, 0, $header, false, true);
            $content = $this->curl->get($link, 20, 5, $header);

            if ($content['content']) {
                $dom = new Dom();
                $dom->load($content['content'], ['whitespaceTextNode' => false,]);
                $url = $content['head']['url'];
                unset($content);
                if (count($dom->find('img[src^=/sorry/]'))) {
                    $this->view->captcha = true;
                    $link = $dom->find('img[src^=/sorry/]')->getAttribute('src');
                    $parse = parse_url($url);
                    $image = "{$parse['scheme']}://{$parse['host']}{$link}";

                    $action = $dom->find('form')[0]->getAttribute('action');
                    $path = substr($parse['path'], 0, strrpos($parse['path'], '/'));
                    $action = "{$parse['scheme']}://{$parse['host']}{$path}/{$action}";

                    $hidden = array();
                    $hidden['action'] = $this->crypt->encryptBase64($action);
                    $hidden['referer'] = $this->crypt->encryptBase64($url);
                    foreach ($dom->find('form input[type=hidden]') as $tag) {
                        $hidden[$tag->getAttribute('name')] = $this->crypt->encryptBase64($tag->getAttribute('value'));
                    }
                    $this->view->hidden_items = $hidden;


                    $content = $this->curl->get($image, 20, 5, array('Referer' => $url, 'No-Cache' => 1));
                    if ($content['content']) {
                        $this->view->captcha_image = 'data:' . @$content['head']['content_type'] . ';base64,' . urlencode(base64_encode($content['content']));
                    }
                    unset($content);
                } else {
                    $index = count($dom->find('#foot .b .csb')) - 1;
                    if ($index >= 0 && strtolower(trim($dom->find('#foot .b .csb')[$index]->nextSibling()->text)) == 'next') {
                        $this->view->have_next = true;
                    }
                    if (!count($dom->find('li.videobox'))) {
                        $this->flash->warning('جست و جوی شما نتیجه ای در بر نداشت.');
                    }

                    foreach ($dom->find('li.videobox') as $index => $li) {
                        $this->view->last_item = $this->view->start + $index + 1;
                        if (isset($websites[$li->find('.kv')->text])) {
                            $item = array();
                            $item['website'] = $websites[$li->find('.kv')->text];
                            $href = $li->find('h3 a')->getAttribute('href');
                            parse_str(substr($href, strpos($href, '?') + 1), $parse);
                            if (!isset($parse['q'])) {
                                continue;
                            }
                            $item['link'] = vinixhash_encode($parse['q']);
                            $item['title'] = $li->find('h3 a')->text;
                            $item['date'] = strtotime($li->find('.st .f .nobr')[0]->text);
                            $item['description'] = strip_tags(substr($li->find('.st')->innerHtml,
                                strpos($li->find('.st')->innerHtml, '<br />')));

                            $item['image'] = '';
                            $src = $li->find('img')[0]->getAttribute('src');
                            if ($src) {
                                $content = $this->curl->get($src, 10, 1);
                                if ($content['content']) {
                                    $item['image'] = 'data:' . @$content['head']['content_type'] . ';base64,' . urlencode(base64_encode($content['content']));
                                }
                                unset($content);
                            }
                            $item['duration'] = '';
                            if (count($li->find('a')[0]->find('div')) == 2) {
                                strtok($li->find('a')[0]->find('div')[1]->text, ' ');
                                $item['duration'] = strtok(' ');
                            }
                            $records[] = (object)$item;
                            unset($item);
                            if (count($records) >= 10) {
                                if (!$this->view->have_next && $index < count($dom->find('li.videobox')) - 1) {
                                    $this->view->have_next = true;
                                }
                                break;
                            }
                        }
                    }
                }
                unset($dom);
                $this->view->records = $records;
                unset($records);
            } else {
                $this->flash->error('جست و جوی مورد نظر شما انجام نشد. لطفا مجددا تلاش نمایید.');
            }
        }


    }

    public function filesAction()
    {
        if ($this->view->suspended) {
            $this->view->disable();
            $this->response->redirect(['for' => 'support']);
            return;
        }
        if (!$this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'login']);
            return;
        }
        $this->view->title = 'لیست ویدئوها';
        $this->view->status = array(
            'Waiting' => 'در انتظار پردازش',
            'InProgress' => 'در حال دریافت',
            'Transferring' => 'آماده سازی فایل',
            'Failed' => 'خطا',
            'Success' => 'موفق',
            'Prominent' => 'برگزیده',
        );

        $currentPage = $this->dispatcher->getParam(0);
        if ($currentPage < 1) {
            $currentPage = 1;
        }
        $name = preg_replace('/(\s+)/', '%', $this->dispatcher->getParam(1));
        if ($name) {
            $files = Files::find([
                'user_id = :user: AND label LIKE :name:',
                'bind' => [
                    'user' => $this->auth->getIdentity()->getId(),
                    'name' => '%' . $name . '%',
                ],
                'order' => 'created_at DESC',
            ]);
        } else {
            $files = Files::find([
                'user_id = :user:',
                'bind' => [
                    'user' => $this->auth->getIdentity()->getId(),
                ],
                'order' => 'created_at DESC',
            ]);
        }

        $paginator = new PaginatorModel([
            'data' => $files,
            'limit' => 10,
            'page' => $currentPage
        ]);
        $this->view->page = $paginator->getPaginate();

        $list = array();
        $servers = Servers::find(['deleted_at = 0']);
        if ($servers) {
            foreach ($servers as $server) {
                $list[$server->getId()] = $server;
            }
        }
        $this->view->servers = $list;
        unset($list, $servers);
    }

    public function shopAction()
    {
        $this->view->title = 'خرید';

        $back = $this->dispatcher->getParam('back');
        if ($back) {
            $gateway = '\\Shariftube\\GateWays\\' . $back;
            if (class_exists($gateway)) {
                $gateway = new $gateway;
                try {
                    $purchase = $gateway->back();
                    if ($purchase) {
                        if ($purchase->doPayment()) {
                            $this->auth->authUserById($purchase->user_id, false);
                            $this->flash->success('پرداخت شما با موفقیت انجام شد.');
                        } else {
                            $this->flash->error('پرداخت شما انجام شد. اما به دلیل خطایی در سرور تا لحظاتی دیگر حجم خریداری شده به حسابتان منظور خواهد شد.');
                        }
                    } else {
                        $this->flash->error('خطای ناشناخته. لطفا با پشتیبانی تماس بگیرید.');
                    }
                } catch (\Exception $e) {
                    $this->flash->error($e->getMessage());
                }
            } else {
                $this->flash->error('پرداخت شما پردازش نشد. لطفا با پشتیبانی تماس بگیرید.');
            }
        }

        if ($this->view->suspended) {
            $this->view->disable();
            $this->response->redirect(['for' => 'support']);
            return;
        }
        if (!$this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'login']);
            return;
        }

        $id = $this->request->getPost('id');
        if (!$back && $id > 0) {
            $package = Packages::findFirst([
                "id = :id: AND status = 'Enable'",
                'bind' => [
                    'id' => $id,
                ],
            ]);
            if ($package && ($this->auth->getIdentity()->role == 'Admin' || $package->price > 1000)) {

                $purchase = new Purchases();
                $purchase->user_id = $this->auth->getIdentity()->getId();
                $purchase->package_id = $package->getId();
                $purchase->amount = $package->price;
                $purchase->gateway = 'Payline';
                $purchase->status = 'Waiting';
                try {
                    if ($purchase->save() && $purchase->send()) {
                        $this->view->disable();
                    } else {
                        $this->flash->error('عملیان پرداخت با مشکل روبرو شد. لطفا مجددا تلاش نمایید..');
                    }
                } catch (\Exception $e) {
                    $this->flash->error($e->getMessage());
                }
            } else {
                $this->flash->error('پکیج مورد نظر شمادر سیستم یافت نشد.');
            }
        }

        $qry = '';
        if ($this->auth->getIdentity()->role != 'Admin') {
            $qry = ' AND price > 1000';
        }

        $this->view->records = Packages::find([
            "status = 'Enable'{$qry}",
            'order' => 'price',
        ]);
        if (!$this->view->records) {
            $this->flash->warning('در حال حاضر هیچ پکیجی برای خرید وجود ندارد.');
        }
    }

    public function unsubscribeAction()
    {
        $this->view->header = false;
        $this->view->title = 'حذف ایمیل شما از خبرنامه';
        $this->view->email = $email = vinixhash_decode($this->dispatcher->getParam('email'));
        if (Unsubscribes::count([
            'email = :email:',
            'bind' => [
                'email' => $email,
            ],
        ])
        ) {
            $this->flash->success('ایمیل شما قبلا از خبرنامه حذف شده است.');
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $unsubscribe = new Unsubscribes();
            $unsubscribe->email = $email;
            if ($unsubscribe->save()) {
                $this->flash->success('ایمیل شما با موفقیت از خبرنامه حذف گردید.');
            } else {
                $this->flash->error('متاسفانه ایمیل شما از خبرنامه حذف نشد. لطفا مجددا تلاش نمایید.');
            }
        } else {
            $this->flash->error('ایمیل شما در لیست خبرنامه ما موجود نیست.');
        }
    }

    public function purchasesAction()
    {
        if ($this->view->suspended) {
            $this->view->disable();
            $this->response->redirect(['for' => 'support']);
            return;
        }
        if (!$this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'login']);
            return;
        }
        $this->view->title = 'لیست خریدهای گذشته';
        $this->view->status = array(
            'Waiting' => 'در انتظار پرداخت',
            'Paid' => 'پرداخت شده',
            'Success' => 'تکمیل شده',
            'Cancelled' => 'کنسل شده',
        );

        $currentPage = $this->dispatcher->getParam('page');
        if ($currentPage < 1) {
            $currentPage = 1;
        }

        $purchases = Purchases::find([
            "user_id = :user: AND deleted_at = 0",
            'bind' => [
                'user' => $this->auth->getIdentity()->getId(),
            ],
            'order' => 'created_at DESC',
        ]);


        $paginator = new PaginatorModel([
            'data' => $purchases,
            'limit' => 10,
            'page' => $currentPage
        ]);
        $this->view->page = $paginator->getPaginate();

        $list = array();
        $packages = Packages::find();
        if ($packages) {
            foreach ($packages as $package) {
                $list[$package->getId()] = $package;
            }
        }
        $this->view->packages = $list;
        unset($list, $packages);
    }

    public function ticketsAction()
    {
        if (!$this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'login']);
            return;
        }
        $this->view->title = 'پشتیبانی';
        $this->view->status = array(
            'Open' => 'باز',
            'Answered' => 'پاسخ داده شده',
            'Replay' => 'پاسخ کاربر',
            'InProgress' => 'در حال انجام',
            'Closed' => 'بسته شده',
        );

        $currentPage = $this->dispatcher->getParam(0);
        if ($currentPage < 1) {
            $currentPage = 1;
        }
        $status = strtolower($this->dispatcher->getParam(1));
        if (!in_array($status, ['open', 'all', 'closed'])) {
            if ($this->view->admin) {
                $status = 'open';
            } else {
                $status = 'all';
            }
        }
        $this->dispatcher->setParam(1, $status);

        switch ($status) {
            case 'open':
                $status = ['Open', 'Replay', 'InProgress'];
                break;
            case 'closed':
                $status = ['Answered', 'Closed'];
                break;
            default:
                $status = ['Open', 'Answered', 'Replay', 'InProgress', 'Closed'];
                break;
        }
        if ($this->view->admin) {
            $tickets = Tickets::find([
                'status IN ({status:array})',
                'bind' => [
                    'status' => $status,
                ],
                'order' => 'modified_at DESC',
            ]);
        } else {
            $tickets = Tickets::find([
                'user_id = :user: AND status IN ({status:array})',
                'bind' => [
                    'user' => $this->auth->getIdentity()->getId(),
                    'status' => $status,
                ],
                'order' => 'modified_at DESC',
            ]);
        }


        $paginator = new PaginatorModel([
            'data' => $tickets,
            'limit' => 10,
            'page' => $currentPage
        ]);
        $this->view->page = $paginator->getPaginate();
    }

    public function ticketAction()
    {
        if (!$this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'login']);
            return;
        }
        $this->view->status = array(
            'Open' => 'باز',
            'Answered' => 'پاسخ داده شده',
            'Replay' => 'پاسخ کاربر',
            'InProgress' => 'در حال انجام',
            'Closed' => 'بسته شده',
        );
        $this->view->id = $id = $this->dispatcher->getParam('id');
        if ($id > 0) {
            $this->view->title = 'مشاهده تیکت';
            $ticket = Tickets::findFirst([
                'id = :id:',
                'bind' => [
                    'id' => $id,
                ],
            ]);
            if ($ticket->user_id != $this->auth->getIdentity()->getId() && !$this->view->admin) {
                $this->view->disable();
                $this->response->redirect(['for' => 'support']);
                return;
            }
            $this->view->ticket = $ticket;

            if ($this->request->getPost('save') || $this->request->getPost('close')) {
                $error = array();

                if ($ticket->user_id == $this->auth->getIdentity()->getId()) {
                    $status = 'Replay';
                } else {
                    $status = 'Answered';
                }

                if ($this->view->admin && in_array($this->request->getPost('status'), ['Open', 'Answered', 'Replay', 'InProgress', 'Closed'])) {
                    $status = $this->request->getPost('status');
                }

                if ($this->request->getPost('close')) {
                    $status = 'Closed';
                }

                $content = $this->request->getPost('content');
                if (mb_strlen($content, 'UTF-8') < 10) {
                    $error[] = 'متن تیکت را به صورت کامل وارد نمایید.';
                } elseif (mb_strlen($content, 'UTF-8') > 1000) {
                    $error[] = 'توضیحات شما نباید بیشتر از ۱۰۰۰ کاراکتر باشد.';
                }

                if (empty($error)) {
                    $transaction = $this->transaction->get();
                    $ticket->setTransaction($transaction);
                    $ticket->status = $status;
                    if (!$ticket->save()) {
                        $transaction->rollback();
                        $error[] = 'پاسخ شما ذخیره نشد. لطفا لحظاتی بعد تلاش کنید.';
                    }
                }
                if (empty($error)) {
                    $replay = new TicketReplays();
                    $replay->setTransaction($transaction);
                    $replay->content = $content;
                    $replay->user_id = $this->auth->getIdentity()->getId();
                    $replay->ticket_id = $ticket->getId();
                    if (!$replay->save()) {
                        $transaction->rollback();
                        $error[] = 'پاسخ شما ذخیره نشد. لطفا لحظاتی بعد تلاش کنید.';
                    } else {
                        $transaction->commit();
                        if (in_array($status, ['Open', 'Answered', 'InProgress', 'Closed'])) {
                            $user = $ticket->getUser();
                            $this->mail->setTemplate('replay');
                            $this->mail->setVar('user', $user);
                            $this->mail->setVar('ticket', $ticket);
                            $this->mail->setVar('link', $this->url->getStatic(['for' => 'ticket', 'id' => $ticket->getId()]));
                            $this->mail->addAddress($user->email, $user->name);
                            $this->mail->Subject = sprintf('پاسخ جدید به تیکت شماره %s', $ticket->getId());
                            $this->mail->send();
                        }
                        $this->flash->success('با تشکر از پاسخ شما.');
                    }
                }
                if (!empty($error)) {
                    foreach ($error as $message) {
                        $this->flash->error($message);
                    }
                }
            }
        } else {
            $this->view->title = 'ارسال تیکت جدید';
            if ($this->view->admin) {
                $this->view->disable();
                $this->response->redirect(['for' => 'support']);
                return;
            }
            if ($this->request->getPost('save')) {
                $error = array();

                $title = $this->request->getPost('title');
                if (mb_strlen($title, 'UTF-8') < 10) {
                    $error[] = 'عنوان تیکت باید حداقل ۱۰ کاراکتر باشد.';
                }

                $content = $this->request->getPost('content');
                if (mb_strlen($content, 'UTF-8') < 10) {
                    $error[] = 'متن تیکت را به صورت کامل وارد نمایید.';
                } elseif (mb_strlen($content, 'UTF-8') > 1000) {
                    $error[] = 'توضیحات شما نباید بیشتر از ۱۰۰۰ کاراکتر باشد.';
                }

                if (empty($error)) {
                    $transaction = $this->transaction->get();
                    $ticket = new Tickets();
                    $ticket->setTransaction($transaction);
                    $ticket->title = $title;
                    $ticket->user_id = $this->auth->getIdentity()->getId();
                    $ticket->status = 'Open';
                    if (!$ticket->save()) {
                        $transaction->rollback();
                        $error[] = 'تیکت جدید ایجاد نشد. لطفا لحظاتی بعد تلاش کنید.';
                    }
                }
                if (empty($error)) {
                    $replay = new TicketReplays();
                    $replay->setTransaction($transaction);
                    $replay->content = $content;
                    $replay->user_id = $this->auth->getIdentity()->getId();
                    $replay->ticket_id = $ticket->getId();
                    if (!$replay->save()) {
                        $transaction->rollback();
                        $error[] = 'تیکت جدید ایجاد نشد. لطفا لحظاتی بعد تلاش کنید.';
                    } else {
                        $transaction->commit();
                        $this->mail->setTemplate('ticket');
                        $this->mail->setVar('user', $this->auth->getIdentity());
                        $this->mail->setVar('ticket', $ticket);
                        $this->mail->setVar('link', $this->url->getStatic(['for' => 'ticket', 'id' => $ticket->getId()]));
                        $this->mail->addAddress($this->auth->getIdentity()->email, $this->auth->getIdentity()->name);
                        $this->mail->Subject = sprintf('تیکت جدید به شماره %s ایجاد شد', $ticket->getId());
                        $this->mail->send();
                        $this->flash->success('تیکت شما با موفقیت ارسال شد و به زودی توسط اپراتورهای پشتیبانی پاسخ داده خواهد شد.');
                        $this->view->id = $id = $ticket->getId();
                        $this->view->ticket = $ticket;
                        $this->dispatcher->setParam('id', $id);
                    }
                }
                if (!empty($error)) {
                    foreach ($error as $message) {
                        $this->flash->error($message);
                    }
                }
            }
        }

    }

    public function settingsAction()
    {
        if ($this->view->suspended) {
            $this->view->disable();
            $this->response->redirect(['for' => 'support']);
            return;
        }
        if (!$this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'login']);
            return;
        }
        $this->view->title = 'تنظیمات';
        $this->view->user = $this->auth->getIdentity();

        if ($this->request->getPost('apply')) {
            $error = array();

            $password = $this->request->getPost('new_password');
            if (strlen($password) > 0 && strlen($password) < 6) {
                $error[] = 'رمز عبور حداقل باید ۶ کاراکتر باشد.';
            }

            if (strlen($password) > 0 && $password != $this->request->getPost('confirm_password')) {
                $error[] = 'رمز عبور و تکرار آن برابر نیستند.';
            }

            $name = $this->request->getPost('name');
            if (mb_strlen($name, 'UTF-8') < 5) {
                $error[] = 'لطفا نام خود را به صورت کامل وارد کنید.';
            }

            if (!$this->security->checkHash($this->request->getPost('password'), $this->auth->getIdentity()->password)
            ) {
                $error[] = 'پسورد فعلی خود را اشتباه وارد کرده اید.';
            }
            if (empty($error)) {
                $this->auth->getIdentity()->name = $name;
                $password_change = false;
                if (strlen($password) > 0) {
                    $this->auth->getIdentity()->password = $this->security->hash($password);
                    $password_change = true;
                }
                if ($this->auth->getIdentity()->save()) {
                    if ($password_change) {
                        $password_change = new PasswordChanges();
                        $password_change->user_id = $this->auth->getIdentity()->getId();
                        $password_change->ip_address = $this->request->getClientAddress();
                        $password_change->user_agent = $this->request->getUserAgent();
                        $password_change->save();
                    }
                    $this->flash->success('تغییرات شما با موفقیت ذخیره شد.');
                } else {
                    $this->flash->error('تغییر شما ذخیره نشد. لطفا مجددا تلاش نمایید.');
                }
            }
            if (!empty($error)) {
                foreach ($error as $message) {
                    $this->flash->error($message);
                }
            }
        }
    }

    public function logoutAction()
    {
        $this->view->disable();
        $this->auth->remove();
        $this->response->redirect(['for' => 'login']);
    }

    public function loginAction()
    {
        $this->view->header = false;
        $this->view->title = 'ورود به سایت';
        if ($this->auth->getIdentity()) {
            $this->view->disable();
            $this->response->redirect(['for' => 'home']);
            return;
        }

        $code = $this->dispatcher->getParam('code');
        if ($code) {
            $reset = ResetPasswords::findFirstByCode($code);
            if (empty($reset) || strtotime($reset->created_at) < time() - 86400 || !$reset->getUser()) {
                $this->flash->error('لینک تغییر رمز عبور اشتباه است. لطفا به آخرین ایمیلی که برای فراموشی رمز عبور دریافت کرده اید مراجعه کنید.');
            } else {
                $password = mt_rand(100000, 999999);
                $user = $reset->getUser();
                $user->password = $this->security->hash($password);
                if (!$user->save()) {
                    $this->flash->error('تغییر رمز عبور انجام نشد. لطفا مجددا تلاش نمایید.');
                } else {
                    $password_change = new PasswordChanges();
                    $password_change->user_id = $user->getId();
                    $password_change->ip_address = $this->request->getClientAddress();
                    $password_change->user_agent = $this->request->getUserAgent();
                    $password_change->save();

                    $this->mail->setTemplate('password');
                    $this->mail->setVar('password', $password);
                    $this->mail->setVar('user', $user);
                    $this->mail->setVar('link', $this->url->getStatic(['for' => 'login']));
                    $this->mail->addAddress($user->email, $user->name, true);
                    $this->mail->Subject = 'رمز عبور جدید';
                    if ($this->mail->send()) {
                        $reset->delete();
                        $this->flash->success('رمز عبور شما تغییر کرد و رمز عبور جدید برای شما ایمیل شد.');
                    } else {
                        $this->flash->error('ایمیل رمز عبور جدید برای شما ارسال نشد. لطفا مجددا تلاش نمایید.');
                    }
                }
            }
        }

        if ($this->request->getPost('login')) {
            if ($this->auth->check([
                'email' => $this->request->getPost('email'),
                'password' => $this->request->getPost('password'),
            ])
            ) {
                $this->view->disable();
                $this->response->redirect(['for' => 'home']);
                return;
            } else {
                $this->flash->error('ایمیل یا رمز عبور اشتباه است.');
            }
        }

        if ($this->request->getPost('signup')) {
            $error = array();

            $email = strtolower(trim($this->request->getPost('email')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error[] = 'ایمیل خود را به صورت صحیح وارد نمایید.';
            }
            if ($email != strtolower(trim($this->request->getPost('email_confirm')))) {
                $error[] = 'تکرار ایمیل با ایمیل وارد شده یکسان نیستند.';
            }
            if (Users::find([
                'deleted_at = 0 AND email = :email:',
                'bind' => [
                    'email' => $email,
                ],
            ])->count()
            ) {
                $error[] = 'با این ایمیل یک کاربر وجود دارد.';
            }

            $password = $this->request->getPost('password');
            if (strlen($password) < 6) {
                $error[] = 'رمز عبور حداقل باید ۶ کاراکتر باشد.';
            }

            $name = $this->request->getPost('name');
            if (mb_strlen($name, 'UTF-8') < 3) {
                $error[] = 'لطفا نام خود را به صورت کامل وارد کنید.';
            }

            $code = $this->request->getPost('code');
            $referral = Users::findFirst([
                'deleted_at = 0 AND referral_code = :code:',
                'bind' => [
                    'code' => $code,
                ],
            ]);
            if (empty($referral)) {
                $error[] = 'کد معرف شما اشتباه می باشد.';
            }

            if (empty($error)) {
                $user = Users::findFirst([
//                    'deleted_at = 0 AND email = :email:',
                    'email = :email:',
                    'bind' => [
                        'email' => $email,
                    ],
                ]);
                if ($user) {
                    $user->deleted_at = 0;
                    $user->quota = 0;
                    $user->used = 0;
                    $user->remain = 0;
                } else {
                    $user = new Users();
                }
                $user->email = $email;
                $user->password = $this->security->hash($password);
                $user->name = $name;
                $user->referral_id = $referral->getId();
                if ($user->save() && $this->auth->authUserById($user->getId())) {
                    $this->view->disable();
                    $this->response->redirect(['for' => 'home']);
                    return;
                } else {
                    $error[] = 'سیستم موقتا مشکل دارد. لطفا مجددا تلاش نمایید.';
                }
            }
            foreach ($error as $message) {
                $this->flash->error($message);
            }
        }

        if ($this->request->getPost('forgot')) {
            $error = array();

            $email = $this->request->getPost('email');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error[] = 'ایمیل خود را به صورت صحیح وارد نمایید.';
            } else {
                $user = Users::findFirst([
                    'deleted_at = 0 AND email = :email:',
                    'bind' => [
                        'email' => $email,
                    ],
                ]);
                if (empty($user)) {
                    $error[] = 'کاربری با این ایمیل در سیستم موجود نیست.';
                } else {
                    $reset = new ResetPasswords();
                    $reset->user_id = $user->getId();
                    if ($reset->save()) {
                        ResetPasswords::find([
                            'user_id = :user_id: AND id != :id:',
                            'bind' => [
                                'user_id' => $user->getId(),
                                'id' => $reset->getId(),
                            ],
                        ])->delete();
                        $this->mail->setTemplate('forgot');
                        $this->mail->setVar('code', $reset->code);
                        $this->mail->setVar('user', $user);
                        $this->mail->setVar('link', $this->url->getStatic(['for' => 'forgot', 'code' => $reset->code]));
                        $this->mail->addAddress($user->email, $user->name, true);
                        $this->mail->Subject = 'فراموشی رمز عبور';
                        if ($this->mail->send()) {
                            $this->flash->success('یک ایمیل حاوی لینک تغییر رمز عبور برای شما ارسال شد.');
                        } else {
                            $error[] = 'ایمیل فراموشی رمز عبور برای شما ارسال نشد. لطفا مجددا تلاش نمایید.';
                        }
                    } else {
                        $error[] = 'سیستم موقتا مشکل دارد. لطفا مجددا تلاش نمایید.';
                    }
                }
            }
            foreach ($error as $message) {
                $this->flash->error($message);
            }
        }
    }

    public function route404Action()
    {
        $this->view->header = false;
        $this->view->title = 'آدرس نا معتبر';
    }
}
