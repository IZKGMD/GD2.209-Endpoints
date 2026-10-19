<?php
declare(strict_types=1);

namespace MuchoCore\News;

use PDO;
use RuntimeException;

final readonly class NewsMediaService
{
    public const MAX_IMAGES = 6;
    public function __construct(private PDO $db) {}

    public function forOwner(int $postId): array
    {
        $query=$this->db->prepare('SELECT id,post_id,kind,filename,width,height,target_url FROM mucho_news_media WHERE post_id=? ORDER BY id');
        $query->execute([$postId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Never select binary payloads while listing a feed or rendering the editor. */
    public function forPosts(array $postIds): array
    {
        if (!$postIds) return [];
        $query=$this->db->prepare('SELECT id,post_id,kind,filename,width,height,target_url FROM mucho_news_media WHERE post_id IN ('.implode(',',array_fill(0,count($postIds),'?')).') ORDER BY id');
        $query->execute(array_map('intval',$postIds));$result=[];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int)$row['post_id']][]=$this->metadata($row);
        return $result;
    }

    public function metadata(array $row): array
    {
        return ['id'=>(int)$row['id'],'kind'=>$row['kind'],'filename'=>$row['filename'],
            'width'=>(int)$row['width'],'height'=>(int)$row['height'],
            'url'=>'/news/image.php?file='.$row['filename'],'target_url'=>$row['target_url']];
    }

    /** Called inside the same transaction as the post and native revision. */
    public function apply(int $postId,array $changes): void
    {
        if (!$this->db->inTransaction()) throw new RuntimeException('News media changes require a transaction.');
        $remove=$changes['remove'] ?? [];$images=$changes['images'] ?? [];
        if (!is_array($remove) || !is_array($images) || count($images)>self::MAX_IMAGES) throw new NewsValidationException('Attach at most six images per post.');
        foreach ($remove as $id) {
            if (filter_var($id,FILTER_VALIDATE_INT)===false || (int)$id<1) throw new NewsValidationException('Invalid image selection.');
            $query=$this->db->prepare("DELETE FROM mucho_news_media WHERE id=? AND post_id=? AND kind='image'");
            $query->execute([(int)$id,$postId]);
        }
        $count=$this->db->prepare("SELECT COUNT(*) FROM mucho_news_media WHERE post_id=? AND kind='image'");
        $count->execute([$postId]);
        if ((int)$count->fetchColumn()+count($images)>self::MAX_IMAGES) throw new NewsValidationException('Keep at most six images per post. Remove an old image before adding more.');
        foreach ($images as $image) {
            if (!$image instanceof NewsImage) throw new RuntimeException('Invalid image data.');
            $this->insert($postId,'image',$image,null);
        }
        if (array_key_exists('video',$changes)) {
            $query=$this->db->prepare("DELETE FROM mucho_news_media WHERE post_id=? AND kind='youtube'");$query->execute([$postId]);
            if ($changes['video']!==null) {
                $video=$changes['video'];
                if (!is_array($video) || !(($video['image'] ?? null) instanceof NewsImage)) throw new RuntimeException('A video preview is required.');
                $url=YouTubeVideo::canonicalUrl((string)($video['url'] ?? ''));
                if ($url==='') throw new RuntimeException('A video link is required.');
                $this->insert($postId,'youtube',$video['image'],$url);
            }
        }
    }

    private function insert(int $postId,string $kind,NewsImage $image,?string $url): void
    {
        $query=$this->db->prepare('INSERT INTO mucho_news_media (post_id,kind,filename,width,height,target_url,image_data) VALUES (?,?,?,?,?,?,?)');
        $query->execute([$postId,$kind,$image->filename,$image->width,$image->height,$url,$image->bytes()]);
    }

    /** Drafts are served only through the owner-authenticated admin endpoint. */
    public function image(string $filename,bool $owner=false): ?string
    {
        if (!preg_match('/^mcnews_[a-f0-9]{64}\.jpg$/D',$filename)) return null;
        $query=$this->db->prepare('SELECT m.image_data FROM mucho_news_media m JOIN mucho_news_posts p ON p.id=m.post_id WHERE m.filename=?'.($owner?'':' AND p.is_published=1').' LIMIT 1');
        $query->execute([$filename]);$bytes=$query->fetchColumn();
        return is_string($bytes)?$bytes:null;
    }
}
