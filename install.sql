-- 车掌柜 完整数据库（全新安装用）
-- 导入前请先在phpMyAdmin选中你要使用的数据库
-- 超管账号：admin  密码：admin123

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS shops, users, cars, services, items, announcements, settings, banners;
SET FOREIGN_KEY_CHECKS=1;

-- 店铺
CREATE TABLE shops (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(50) COMMENT '店名',
  phone VARCHAR(20),
  expire_date DATE NULL COMMENT '到期时间',
  status TINYINT DEFAULT 1 COMMENT '1正常 0停用',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 用户（老板+徒弟）
CREATE TABLE users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  shop_id INT,
  phone VARCHAR(20) UNIQUE,
  password_hash VARCHAR(255),
  name VARCHAR(20) DEFAULT '老板',
  role ENUM('boss','staff') DEFAULT 'boss',
  token VARCHAR(255) NULL,
  last_ip VARCHAR(50) DEFAULT '',
  last_active DATETIME NULL,
  default_months INT DEFAULT 6 COMMENT '默认提醒月数',
  default_km INT DEFAULT 5000 COMMENT '默认提醒里程',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 车辆
CREATE TABLE cars (
  id INT PRIMARY KEY AUTO_INCREMENT,
  shop_id INT,
  plate VARCHAR(20),
  owner_name VARCHAR(50) COMMENT '备注称呼',
  phone VARCHAR(20) COMMENT '车主电话',
  car_info VARCHAR(100) COMMENT '车型颜色',
  tags VARCHAR(255) COMMENT '标签逗号分隔',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 工单/记录
CREATE TABLE services (
  id INT PRIMARY KEY AUTO_INCREMENT,
  shop_id INT,
  car_id INT,
  service_date DATE,
  mileage INT NULL,
  items TEXT COMMENT '服务内容',
  amount DECIMAL(10,2),
  is_credit TINYINT DEFAULT 0 COMMENT '0已收款 1赊账',
  credit_settled TINYINT DEFAULT 0 COMMENT '赊账是否已结清',
  next_remind_date DATE NULL,
  next_remind_km INT NULL,
  remind_done TINYINT DEFAULT 0 COMMENT '提醒是否已处理',
  staff_name VARCHAR(20) DEFAULT '' COMMENT '操作人',
  rescue_type VARCHAR(20) DEFAULT '' COMMENT '救援类型',
  rescue_note TEXT COMMENT '救援备注',
  photos TEXT COMMENT '工单照片JSON数组',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 常用项目
CREATE TABLE items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  shop_id INT,
  name VARCHAR(50),
  price DECIMAL(10,2),
  sort_order INT DEFAULT 0
);

-- 公告
CREATE TABLE announcements (
  id INT PRIMARY KEY AUTO_INCREMENT,
  type ENUM('scroll','popup'),
  content TEXT,
  expire_date DATE,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 全局设置
CREATE TABLE settings (
  k VARCHAR(50) PRIMARY KEY,
  v TEXT
);

-- 初始配置（超管密码admin123首次登录自动写入）
INSERT INTO settings (k,v) VALUES
('default_plan','free'),
('baidu_api_key',''),
('baidu_secret_key','');

-- 轮播图
CREATE TABLE banners (
  id INT PRIMARY KEY AUTO_INCREMENT,
  image_url VARCHAR(255) COMMENT '图片地址',
  link_url VARCHAR(255) COMMENT '点击跳转',
  sort_order INT DEFAULT 0,
  status TINYINT DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
