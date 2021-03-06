<?php

namespace App\Http\Controllers;

use App\Book;
use App\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApiController extends Controller
{
    public function __construct()
    {
    
    }

    public function index(Request $request)
    {
        if ($request->wantsJson())
            return $this->success('Hello Zneiat/book-register API');
        
        return view('index');
    }
    
    /**
     * 当前时间获取
     *
     * @inheritdoc
     */
    public function getTime(Request $request)
    {
        return $this->success('获取服务器时间成功', [
            'time'        => time(),
            'time_format' => date('Y-m-d H:i:s', time()),
        ]);
    }
    
    /**
     * 获取用户资料
     *
     * @inheritdoc
     */
    public function getUser(Request $request)
    {
        $user = trim($request->get('user'));
        
        if (empty($user))
            return $this->error('参数 user 是必须的');
        
        $userCategoryTotal = $this->tableCategory()->where(['user' => $user])->count();
        
        // 个人所有时间登记图书数
        $userBookTotal = $this->tableBook()
            ->where(['user' => $user])
            ->where('name', '!=', '')
            ->count();
    
        // 个人今日登记图书数
        $userBookTodayTotal = $this->tableBook()
            ->whereRaw("Date(updated_at) = ?", date('Y-m-d'))
            ->where(['user' => $user])
            ->where('name', '!=', '')
            ->count();
        
        // 全站今日登记图书数
        $siteBookTodayTotal = $this->tableBook()
            ->whereRaw("Date(updated_at) = ?", date('Y-m-d'))
            ->where('name', '!=', '')
            ->count();
        
        return $this->success('获取用户资料成功', [
            'user_category_total'   => $userCategoryTotal,
            'user_book_total'       => $userBookTotal,
            'user_book_today_total' => $userBookTodayTotal,
            'site_book_today_total' => $siteBookTodayTotal
        ]);
    }
    
    /**
     * 获取排名
     *
     * @inheritdoc
     */
    public function getRanking(Request $request)
    {
        $userBookRanking = new Collection();
        $siteBookTotal = $this->tableBook()->count();
        $siteCategoryTotal = $this->tableCategory()->count();
        $userBooks = $this->tableBook('`category_name` ASC')->get()->groupBy('user')->all();
        foreach ($userBooks as $userName => $books) {
            $userBookCount = $books->count();
            $userBookRanking->push([
                'user_name' => $userName,
                'book_count' => $userBookCount,
                'percentage' => $userBookCount > 0 ? round(($userBookCount / $siteBookTotal) * 100, 2) : 0
            ]);
        }
        
        return $this->success('获取排名数据成功', [
            'book_ranking' => $userBookRanking->sortByDesc('book_count')->values()->toArray(),
            'site_category_total' => $siteCategoryTotal,
            'site_book_total' => $siteBookTotal,
        ]);
    }
    
    /**
     * 获取分类
     *
     * @inheritdoc
     */
    public function getCategory(Request $request)
    {
        $name = trim($request->get('name'));
        $withBooks = trim($request->get('withBooks'));
        
        $categoriesTable = $this->tableCategory(); // '`name` ASC'
        
        if (!empty($name)) {
            $categories = $categoriesTable->where(['name' => $name]);
            if (!$categories->exists())
                return $this->error('该类目不存在');
            $categories = $categories->limit(1)->get();
        }
        else {
            $categories = $categoriesTable->get();
        }
        
        $apiData = [];
        foreach ($categories as $item) {
            /** @var $item Category */
            $booksCount = $item->books->count();
            $itemCategoryName = $item->name;
            
            $users = [];
            $workers = $item->books->groupBy('user')->keys()->all();
            foreach ($workers as $username) {
                $userBookCount = $item->books->where('user', $username)->count();
                $users[] = [
                    'username' => $username,
                    'books_count' => $userBookCount,
                    'percentage' => $booksCount > 0 ? round(($userBookCount / $booksCount) * 100, 2) : 0
                ];
            }
            
            $apiData[$itemCategoryName] = [
                'name'              => $itemCategoryName,
                'user'              => $item->user,
                'users'             => $users,
                'remarks'           => $item->remarks,
                'isDone'            => $item->isDone,
                'books_count'       => $booksCount,
                'updated_at'        => $item->updated_at->timestamp ?? 0,
                'created_at'        => $item->created_at->timestamp ?? 0,
                'updated_at_format' => $item->updated_at ? $item->updated_at->toDateTimeString() : '',
                'created_at_format' => $item->created_at ? $item->created_at->toDateTimeString() : '',
            ];
            
            if (!empty($withBooks) || !empty($name)) {
                $apiData[$itemCategoryName]['books'] = $this->handleBooksForGetting($item->books->toArray());
            }
        }
        
        return $this->success('类目数据获取成功', [
            'categories_total' => $categories->count(),
            'categories'       => $apiData,
        ]);
    }
    
    /**
     * 上传类目数据
     *
     * @inheritdoc
     */
    public function uploadCategory(Request $request)
    {
        $user = trim($request->post('user'));
        $books = trim($request->post('books'));
        $time = \Carbon\Carbon::now()->toDateTimeString();
        
        if (empty($user))
            return $this->error('参数 user 是必须的');
        if (empty($books))
            return $this->error('参数 books 是必须的');
        
        $books = @json_decode($books, true);
        if (json_last_error() !== JSON_ERROR_NONE)
            return $this->error('图书 JSON 解析失败 ' . json_last_error_msg());
        
        // 导入单个类目图书数据
        $importCategoryBookItem = function ($categoryName, $numbering, array $bookItem) use ($user, $time) {
            $numbering = intval($numbering);
            
            // $numbering 永不等于 "0"！
            if (empty($numbering) || (empty($bookItem['name']) && empty($bookItem['press']) && empty($bookItem['remarks'])))
                return;
            
            $attributes = [
                'category_name' => $categoryName,
                'numbering'     => $numbering,
            ];
            
            $values = [
                'user'       => $user,
                'updated_at' => $time,
            ];
    
            $count = $this->tableBook()->where($attributes)->count();
            if ($count > 1) {
                // delete all then create new
                $this->tableBook()->where($attributes)->delete();
                $values['created_at'] = $time;
            } else if ($count == 1) {
                // normal update
            } else if ($count < 1) {
                // create new
                $values['created_at'] = $time;
            }
            
            if (!empty($bookItem['name']))
                $values['name'] = $bookItem['name'];
            
            if (!empty($bookItem['press']))
                $values['press'] = $bookItem['press'];
            
            if (!empty($bookItem['remarks']))
                $values['remarks'] = $bookItem['remarks'];
            
            $this->tableBook()->updateOrInsert($attributes, $values);
            
            return;
        };
        
        $categoryTotal = 0;
        $bookTotal = 0;
        $errors = null;
        
        // 导入多类图书
        foreach ($books as $categoryName => $arr) {
            if (empty($arr)) continue;
            
            try {
                $category = $this->tableCategory()->where([
                    'name' => $categoryName,
                ]);
                
                if (!$category->exists()) {
                    Log::error($user . ' 的数据未导入数据库 ' . json_encode([$categoryName => $arr]));
                    // throw new \Exception('类目 "' . $categoryName . '" 未找到');
                    continue;
                }
                
                foreach ($arr as $numbring => $bookItem) {
                    // Key 即是 Numbring
                    $importCategoryBookItem($categoryName, $numbring, $bookItem);
                    
                    $bookTotal++;
                }
                
                $updateValues = [
                    'updated_at' => $time
                ];
                $category->update($updateValues);
            } catch (\Exception $exception) {
                Log::error('[图书数据导入错误][类目名 = "' . $categoryName . '"] ' . $exception->getMessage() . PHP_EOL . $exception->getTraceAsString());
                $errors[$categoryName] = $exception->getMessage();
                break;
            }
            
            $categoryTotal++;
        }
        
        if ($errors === null) {
            return $this->success('本次共上传了 ' . $categoryTotal . ' 个类目的 ' . $bookTotal . ' 本图书数据');
        } else {
            return $this->error('图书数据保存失败，请联系网站管理员', ['errors' => $errors]);
        }
    }
    
    /**
     * 创建类目
     *
     * @inheritdoc
     */
    public function categoryCreate(Request $request)
    {
        $user = trim($request->get('user'));
        $name = trim($request->get('name'));
        
        if (empty($user))
            return $this->error('参数 user 是必须的');
        if (empty($name))
            return $this->error('参数 name 是必须的');
        
        // 尝试获取类目
        $category = $this->tableCategory()->where([
            'name' => $name,
        ]);
        
        if ($category->exists())
            return $this->success('同名类目已存在，无需再次创建', ['categoryExist' => true, 'categoryName' => $name]);
        
        // 若类目不存在 则创建一个
        $category = new Category();
        $category->name = $name;
        $category->user = $user;
        $category->remarks = '';
        
        if ($category->save())
            return $this->success('类目创建成功', ['categoryExist' => false, 'categoryName' => $name]);
        
        return $this->error('类目创建失败');
    }
    
    /**
     * 数据表格文件下载
     *
     * @inheritdoc
     */
    public function categoryExcel(Request $request)
    {
        // 准备数据
        $categories = $this->tableCategory('`name` ASC');
        $datetime = date('Y-m-d His', time());
        $name = $request->get('name');
        $mode = $request->get('mode', '1');
        $allowMode = ['1', '2'];
        if (!in_array($mode, $allowMode)) $mode = '1';
        
        if (!empty($name)) {
            $categories = $categories->where(['name' => $name]);
            $title = 'Category ' . $name . ' ' . $datetime;
        }
        else {
            $title = 'All Category ' . $datetime;
        }
    
        if (!$categories->exists())
            throw new NotFoundHttpException();
    
        $categories = $categories->get();
    
        $data = [
            ['类目', '序号', '索引号', '书名', '出版社', '备注', '登记员'],
        ];
    
        $buildSingleCategoryData = function (Category $categoryData) use ($mode) {
            $categoryName = $categoryData->name;
        
            if (empty($categoryName))
                return null;
        
            $books = $this->tableBook('CONVERT(`numbering`, SIGNED) ASC')->where([
                'category_name' => $categoryName,
            ]);
        
            if (!$books->exists())
                return null;
            $books = $books->get();
            
            if ($mode === '2')
                $books = json_decode(json_encode($this->handleBooksForGetting($books->toArray())));
            
            $data = [];
            foreach ($books as $bookItem) {
                if ($mode === '1' && (empty($bookItem->numbering) || (empty($bookItem->name) && empty($bookItem->press) && empty($bookItem->remarks))))
                    continue;
            
                $numbering = $bookItem->numbering;
                $numberingFull = $categoryName . ' ' . $numbering;
                $data[] = [
                    $categoryName,
                    $numbering,
                    $numberingFull,
                    $bookItem->name,
                    $bookItem->press,
                    $bookItem->remarks,
                    $bookItem->user,
                ];
            }
        
            return $data;
        };
    
        foreach ($categories as $categoryItem) {
            $itemData = $buildSingleCategoryData($categoryItem);
            if (empty($itemData))
                continue;
            
            foreach ($itemData as $item) {
                $data[] = $item;
            }
        }
        
        \Excel::create($title, function($excel) use ($title, $data) {
            $excel->setTitle($title);
            
            $excel->setCreator('ZNEIAT')
                ->setLastModifiedBy('ZNEIAT')
                ->setSubject('zneiat/book-register CategoryExcel')
                ->setKeywords('books, zneiat')
                ->setCompany('ZNEIAT')
                ->setDescription('zneiat/book-register Automatic Generated.');
            
            $excel->sheet('全部类目', function($sheet) use ($data) {
    
                $sheet->setWidth(['A' => 8, 'B' => 8, 'C' => 10, 'D' => 25, 'E' => 30, 'F' => 20, 'G' => 10]);
                $sheet->rows($data);
                
            });
        })->export('xls');
    }
    
    /**
     * 管理员修改类目
     *
     * @inheritdoc
     */
    public function adminUpdateCategory(Request $request)
    {
        $reqPassword = $request->post('password');
        $adminPassword = env('ADMIN_PASSWORD', '87654321');
        
        if ($reqPassword !== $adminPassword)
            return $this->error('无权访问');
        
        $name = $request->post('name');
        $values = json_decode($request->post('data'), true);
        if (empty($name) || json_last_error() !== JSON_ERROR_NONE)
            return $this->error('参数无效');
        
        $category = (new Category)->where(['name' => $name]);
        if (!$category->exists())
            return $this->error('类目' . $name . ' 不存在');
    
        if (empty($values))
            return $this->success('未做任何修改');
        
        if ($category->update($values))
            return $this->success('类目' . $name . ' 修改成功');
        
        return $this->error('类目' . $name . ' 修改失败');
    }
}
