#!/bin/bash

# 共享救助信息服务平台 - 最终构建脚本

set -e

# 配置
IMAGE_NAME="hyperf-backend-test"
IMAGE_TAG="latest"
CONTAINER_NAME="hyperf-backend-test"
MAX_RETRIES=3

echo "🚀 共享救助信息服务平台 - 最终构建脚本"
echo "=========================================="

# 确保.env.dev文件存在
if [ ! -f ".env.dev" ]; then
    echo "❌ 错误: .env.dev文件不存在!"
    exit 1
fi

echo "✅ 环境配置文件检查通过"
echo "🐘 使用官方hyperf/hyperf:8.2-alpine-v3.20-swoole基础镜像"
echo "📋 使用.env.dev配置文件"
echo "🌐 使用Composer国内镜像源"

# 创建临时构建目录
BUILD_DIR="build-final"
echo "📁 创建临时构建目录: $BUILD_DIR"
rm -rf $BUILD_DIR
mkdir -p $BUILD_DIR

# 复制必要文件
echo "📋 复制应用文件..."
cp -r app $BUILD_DIR/
cp -r config $BUILD_DIR/
cp -r bin $BUILD_DIR/
cp -r database $BUILD_DIR/
cp -r migrations $BUILD_DIR/
cp -r storage $BUILD_DIR/
cp -r runtime $BUILD_DIR/
cp composer.json $BUILD_DIR/
cp composer.lock $BUILD_DIR/
cp .env.dev $BUILD_DIR/.env

# 创建Dockerfile
cat > $BUILD_DIR/Dockerfile << 'EOF'
FROM hyperf/hyperf:8.2-alpine-v3.20-swoole

# 安装系统依赖
RUN apk add --no-cache \
    git \
    curl \
    zip \
    unzip \
    tzdata \
    && rm -rf /var/cache/apk/*

# 官方镜像已预装以下扩展，无需重新安装：
# 验证 Swoole 安装
# RUN php --ri swoole

# 安装Composer
# RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 配置Composer使用国内镜像源
# RUN composer config -g repo.packagist composer https://packagist.phpcomposer.com

# 设置工作目录
WORKDIR /opt/www

# 设置环境变量
ENV TIMEZONE=Asia/Shanghai \
    APP_ENV=test

# 设置系统时区
RUN ln -sf /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \
    && echo "Asia/Shanghai" > /etc/timezone

# 配置PHP
RUN mkdir -p /usr/local/etc/php/conf.d \
    && echo "upload_max_filesize=128M" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "post_max_size=128M" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "memory_limit=2G" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "max_execution_time=1800" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "date.timezone=${TIMEZONE}" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "display_errors=On" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "log_errors=On" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "error_reporting=E_ALL" >> /usr/local/etc/php/conf.d/99-overrides.ini

# 复制应用文件
COPY . /opt/www

# 清理并重新安装依赖
RUN rm -rf /opt/www/vendor \
    && composer install --optimize-autoloader --no-dev --no-scripts \
    && composer dump-autoload --optimize

# 设置权限
RUN chmod -R 755 /opt/www \
    && chmod -R 777 /opt/www/runtime \
    && chmod -R 777 /opt/www/storage

EXPOSE 9510

# 启动命令
CMD ["php", "/opt/www/bin/hyperf.php", "start"]
EOF

# 进入构建目录
cd $BUILD_DIR

# 尝试拉取基础镜像
echo "📥 拉取官方hyperf/hyperf:8.2-alpine-v3.20-swoole基础镜像..."
for i in $(seq 1 $MAX_RETRIES); do
    echo "尝试 $i/$MAX_RETRIES..."
    if docker pull hyperf/hyperf:8.2-alpine-v3.20-swoole; then
        echo "✅ 基础镜像拉取成功"
        break
    else
        echo "❌ 尝试 $i 失败"
        if [ $i -eq $MAX_RETRIES ]; then
            echo "❌ 所有尝试都失败了，使用本地镜像继续..."
        else
            echo "⏳ 等待5秒后重试..."
            sleep 5
        fi
    fi
done

# 构建应用镜像
echo "📦 构建Docker镜像..."
for i in $(seq 1 $MAX_RETRIES); do
    echo "构建尝试 $i/$MAX_RETRIES..."
    if docker buildx build \
    --platform linux/amd64 \
    --provenance=false \
    --no-cache  \
    -t ${IMAGE_NAME}:${IMAGE_TAG}  --load .; then
        echo "✅ 镜像构建成功!"
        break
    else
        echo "❌ 构建尝试 $i 失败"
        if [ $i -eq $MAX_RETRIES ]; then
            echo "❌ 所有构建尝试都失败了"
            cd ..
            rm -rf $BUILD_DIR
            exit 1
        else
            echo "⏳ 等待10秒后重试..."
            sleep 10
        fi
    fi
done

# 返回原目录
cd ..

# 显示镜像信息
echo "📊 镜像信息:"
docker images | grep ${IMAGE_NAME}

# 创建镜像导出文件
EXPORT_FILE="hyperf-backend-test-$(date +%Y%m%d-%H%M%S).tar"
echo "💾 导出镜像到文件: ${EXPORT_FILE}"
docker save ${IMAGE_NAME}:${IMAGE_TAG} -o ${EXPORT_FILE}

# 清理临时目录
echo "🧹 清理临时构建目录..."
rm -rf $BUILD_DIR

echo ""
echo "🎉 构建完成！"
echo "=========================================="
echo "✅ 镜像名称: ${IMAGE_NAME}:${IMAGE_TAG}"
echo "📦 导出文件: ${EXPORT_FILE}"
echo "🌐 服务端口: 9510"
echo ""
echo "📋 使用说明:"
echo "1. 将 ${EXPORT_FILE} 文件复制到目标服务器"
echo "2. 在目标服务器上运行: docker load -i ${EXPORT_FILE}"
echo "3. 启动容器: docker run -d --name ${CONTAINER_NAME} -p 9510:9510 ${IMAGE_NAME}:${IMAGE_TAG}"
echo ""
echo "🧪 快速测试:"
echo "docker run -d --name test-container -p 9510:9510 ${IMAGE_NAME}:${IMAGE_TAG}"
echo "curl http://localhost:9510/api/user/info"
echo "docker stop test-container && docker rm test-container"
echo ""
echo "🌐 服务地址: http://localhost:9510"
echo "📊 查看日志: docker logs -f ${CONTAINER_NAME}"
echo "🛑 停止服务: docker stop ${CONTAINER_NAME}" 