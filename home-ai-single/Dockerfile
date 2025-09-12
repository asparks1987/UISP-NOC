FROM php:8.2-apache

# Install system deps: Python + pip, Supervisor, and build toolchain/libs
# - libffi-dev for cffi (cryptography build deps)
# - libssl-dev and pkg-config for OpenSSL detection
# - build-essential/gcc/g++/make for building native wheels
# - gfortran + libopenblas-dev for numpy/scipy on ARM
# - rustc + cargo for cryptography >= 41 on platforms lacking prebuilt wheels
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       python3 python3-pip python3-venv python3-dev supervisor \
       ca-certificates curl pkg-config \
       build-essential gcc g++ make \
       gfortran libopenblas-dev \
       libffi-dev libssl-dev \
       rustc cargo \
    && rm -rf /var/lib/apt/lists/*

# Enable PHP extensions needed for MySQL
RUN docker-php-ext-install pdo_mysql

# Copy PHP app (adjust path to your actual PHP app)
WORKDIR /var/www/html
COPY ./php/ ./

# Copy Python backend and install dependencies
WORKDIR /opt/home-ai-python
COPY ./python/requirements.txt ./
# Ensure modern pip/setuptools/wheel for building from source on ARM
RUN python3 -m pip install --upgrade pip setuptools wheel \
    && if [ -f requirements.txt ]; then pip3 install --no-cache-dir -r requirements.txt; fi
COPY ./python/ ./

# Default Python start command (override with env if your entrypoint differs)
ENV PYTHON_START_CMD="python3 -m uvicorn app.main:app --host 0.0.0.0 --port 8000"

# Supervisor runs Apache (PHP) and Python app together
COPY ./supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 80 8000
CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
