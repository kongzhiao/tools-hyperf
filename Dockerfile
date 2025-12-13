# 基础环境
FROM hyperf/hyperf:8.2-alpine-v3.20-swoole

# 系统依赖安装
RUN apk add --no-cache \
    git \
    curl \
    zip \
    unzip \
    tzdata \
    && rm -rf /var/cache/apk/*

# 保留工作目录
WORKDIR /opt/www

# 保留原环境变量
ENV TIMEZONE=Asia/Shanghai \
    APP_ENV=test

# 保留原时区配置
RUN ln -sf /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \
    && echo "Asia/Shanghai" > /etc/timezone

# 保留原PHP配置
RUN mkdir -p /usr/local/etc/php/conf.d \
    && echo "upload_max_filesize=128M" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "post_max_size=128M" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "memory_limit=2G" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "max_execution_time=1800" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "date.timezone=${TIMEZONE}" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "display_errors=On" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "log_errors=On" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "error_reporting=E_ALL" >> /usr/local/etc/php/conf.d/99-overrides.ini

# 创建空的代码目录结构（避免挂载后目录缺失）
RUN mkdir -p /opt/www/bin \
    && mkdir -p /opt/www/config \
    && mkdir -p /opt/www/app \
    && mkdir -p /opt/www/runtime \
    && mkdir -p /opt/www/storage

# 【核心修改3】仅保留权限配置（无代码，先给空目录赋权）
RUN chmod -R 755 /opt/www \
    && chmod -R 777 /opt/www/runtime \
    && chmod -R 777 /opt/www/storage

# 保留原暴露端口（不变）
EXPOSE 9501

# 保留原启动命令（挂载代码后生效）
CMD ["php", "/opt/www/bin/hyperf.php", "start"]