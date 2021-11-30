<?php

/**
 * Very simple Router
 * 
 */
class Router
{

    /**
     * URL patterns
     * @var Array
     */
    private $routes;

    /**
     * Run the router
     */
    public function run()
    {
        $path = (parse_url($_SERVER['REQUEST_URI'] ?? '/'))['path'];

        if ($path != "/") $path = trim($path, "/");

        if (array_key_exists($path, $this->routes)) {
            $controller = $this->routes[$path];
            new $controller();
        } else {
            http_response_code(404);
        }

        exit();
    }

    /**
     * Set a new route
     */
    public function set($path, $controller)
    {
        $this->routes[$path] = $controller;
    }
}


$routes = new Router();

$routes->set('/', 'MainPage');
$routes->set('add', 'AddContact');
$routes->set('list', 'MainPage');
$routes->set('remove', 'RemoveContact');

/** this url for filling base */
$routes->set('1000', 'FillWithRussianNames');

$routes->run();



/**
 * Page. DB connect
 * 
 */
class Page
{
    public $pdo;

    function __construct()
    {
        $options  = array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => FALSE
        );

        try {
            $this->pdo = new PDO('sqlite:db.sqlite', '', '', $options);
        } catch (PDOException $e) {
            throw new Exception("DB Error {$e}");
        }

        $isTableExists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='contacts'");


        if (!$isTableExists->fetch()) {
            $this->pdo->query("CREATE TABLE IF NOT EXISTS `contacts` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` VARCHAR(120) NOT NULL, `phone` VARCHAR(20) NOT NULL, `email` VARCHAR(120) NOT NULL, `phone_digits` VARCHAR(20) NOT NULL)");
            $this->pdo->query("CREATE INDEX `name` ON `contacts` (`name` ASC);");
            $this->pdo->query("CREATE INDEX `phone` ON `contacts` (`phone` ASC);");
            $this->pdo->query("CREATE INDEX `email` ON `contacts` (`email` ASC);");
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method == 'POST') {
            $this->post();
        } else if ($method == "GET") {
            $this->get();
        }
    }

    public function get()
    {
        throw new Exception("Method GET not allowed");
    }

    public function post()
    {
        throw new Exception("Method POST not allowed");
    }
}


/**
 * Main Page
 * 
 */
class MainPage extends Page
{
    public $contacts;
    public $page = 1;
    public $pages;
    public $filters;
    public $query;
    public $pagination = [];

    function init()
    {
        $perPage = 10;

        $where = "";
        $values = [];

        if ($this->filters) {
            $this->page = $this->filters['page'] ?? 1;
            unset($this->filters['page']);

            $this->query = "&" . http_build_query($this->filters);

            $wheres = [];
            foreach ($this->filters as $key => $value) {
                if (!$value) continue;
                if ($key == 'page') continue;

                /** for sqlite uppercase first */
                if ($key == 'name') $value = mb_substr(mb_convert_case($value, MB_CASE_UPPER, "UTF-8"), 0, 1) . mb_substr($value, 1);

                /** phone search in phone digits */
                if ($key == 'phone') {
                    $wheres[] = "`phone_digits` LIKE :{$key}";
                } else {
                    $wheres[] = "`{$key}` LIKE :{$key}";
                }

                $values[$key] = "%{$value}%";
            }

            if ($wheres) {
                $where = "WHERE " . implode(" AND ", $wheres);
            }
        }

        $db = $this->pdo->prepare("SELECT count(id) FROM contacts {$where}");
        $count = $db->execute($values);

        $count = $count ? $db->fetchColumn() : 0;

        $this->pages = intval($count / $perPage);
        if (!($count % $perPage)) $this->pages--;


        if ($this->pages > 10) {
            if ($this->page <= 2 || $this->page >= $this->pages-1) {
                
                if ($this->page == 2) $this->pagination[] = [1, 2, 3];
                else $this->pagination[] = [1, 2];

                $this->pagination[] = [0];
                if ($this->page == $this->pages-1) $this->pagination[] = [$this->pages-2, $this->pages-1, $this->pages];
                else $this->pagination[] = [$this->pages-1, $this->pages];

            } else {
                $this->pagination[] = [1];            
                $this->pagination[] = [0];
                $this->pagination[] = [$this->page - 1, $this->page, $this->page + 1];
                $this->pagination[] = [0];
                $this->pagination[] = [$this->pages];
            }
        } else {
            if ($this->pages > 1) $this->pagination[] = range(1, $this->pages);
        }

        $db = $this->pdo->prepare("SELECT * FROM contacts {$where} ORDER BY name ASC LIMIT " . ($this->page - 1) * $perPage . ", {$perPage}");
        $db->execute($values);

        $this->contacts = $db->fetchAll();
    }

    function get()
    {
        $this->filters = $_GET;
        $this->init();
        include('templates/index.html');
    }

    function post()
    {
        $this->filters = $_POST;
        $this->init();
        include('templates/list.html');
    }
}


/**
 * Add Contact 
 * 
 */
class AddContact extends Page
{
    function post()
    {
        $values = [
            'name' => $_POST['name'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? '',
            'phone_digits' => preg_replace('/\D+/', '', $_POST['phone'] ?? '')
        ];

        $list = $this->pdo->prepare('INSERT INTO contacts (name, phone, email, phone_digits) VALUES (:name, :phone, :email, :phone_digits)');
        $list->execute($values);

        header("Location:/");
    }
}

/**
 * Remove Contact 
 * 
 */
class RemoveContact extends MainPage
{
    function post()
    {
        $values = [
            'id' => intval($_POST['id'] ?? 0)
        ];

        $list = $this->pdo->prepare('DELETE FROM contacts WHERE id=:id');
        $list->execute($values);

        $this->filters = $_POST;
        unset($this->filters['id']);

        $this->init();
        include('templates/list.html');
    }
}


/**
 * Autofill base with 1000 lines
 * 
 */
class FillWithRussianNames extends Page
{
    private $n, $ready, $fmale, $ffemale, $flast;

    public function get()
    {
        $this->n = 500;

        $this->fmale = dirname(__FILE__) . '/sync/' . 'male.txt';
        $this->ffemale = dirname(__FILE__) . '/sync/' . 'female.txt';
        $this->flast = dirname(__FILE__) . '/sync/' . 'last.txt';

        $this->ready =
            (file_exists($this->fmale) and filesize($this->fmale) > 1 and
                file_exists($this->ffemale) and filesize($this->ffemale) > 1 and
                file_exists($this->flast) and filesize($this->flast) > 1);

        $this->fill(false);
        $this->fill(true);

        header("Location:/");
    }

    private function getWomanForm($last): string
    {
        $last = preg_replace("/^(.+)(ов|ев|ёв|ин|ын)$/", "$1$2а", $last);
        $last = preg_replace("/^(.+)(ый|ий)$/", "$1ая", $last);
        return $last;
    }

    private function slugify($string): string
    {
        $string = transliterator_transliterate("Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; [:Punctuation:] Remove; Lower();", $string);
        $string = mb_ereg_replace('[-\s]+', '.', $string);
        $string = str_replace('ʹ', 'i', $string);
        return trim($string, '.');
    }

    private function randomEmail($name): string
    {
        $inboxes = ['mail.ru', 'gmail.com', 'inbox.ru', 'yandex.ru', 'bk.ru', 'rambler.ru'];
        $email = $this->slugify($name) . '@' . $inboxes[mt_rand(0, count($inboxes) - 1)];
        return $email;
    }

    private function randomPhone(): string
    {
        $seed = mt_rand(0, 4);

        switch ($seed) {
            case 0:
                $phone = "+7 (" . mt_rand(900, 999) . ") " . mt_rand(100, 999) . "-" . mt_rand(10, 99) . "-" . mt_rand(10, 99);
                break;
            case 1:
                $phone = "+7 " . mt_rand(900, 999) . " " . mt_rand(100, 999) . " " . mt_rand(10, 99) . " " . mt_rand(10, 99);
                break;
            case 2:
                $phone = "8-" . mt_rand(900, 999) . "-" . mt_rand(100, 999) . "-" . mt_rand(10, 99) . "" . mt_rand(10, 99);
                break;
            case 3:
                $phone = "+7 " . mt_rand(900, 999) . " " . mt_rand(100, 999) . "-" . mt_rand(10, 99) . "-" . mt_rand(10, 99);
                break;

            default:
                $phone = "+7" . mt_rand(900, 999) . "" . mt_rand(100, 999) . "" . mt_rand(10, 99) . "" . mt_rand(10, 99);
                break;
        }

        return $phone;
    }

    public function fill($gender = true)
    {
        $male = file($this->fmale, FILE_IGNORE_NEW_LINES);
        $female = file($this->ffemale, FILE_IGNORE_NEW_LINES);
        $last = file($this->flast, FILE_IGNORE_NEW_LINES);
        shuffle($male);
        shuffle($female);
        shuffle($last);

        for ($i = 0; $i < $this->n; $i++) {
            $name = $gender ? $male[$i] . ' ' . $last[$i] : $female[$i] . ' ' . $this->getWomanForm($last[$i]);
            $phone = $this->randomPhone();

            $values = [
                'name' => $name,
                'phone' => $phone,
                'email' => $this->randomEmail($name),
                'phone_digits' => preg_replace('/\D+/', '', $phone ?? '')
            ];

            $list = $this->pdo->prepare('INSERT INTO contacts (name, phone, email, phone_digits) VALUES (:name, :phone, :email, :phone_digits)');
            $list->execute($values);
        }
    }
}
