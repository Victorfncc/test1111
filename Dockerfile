# Use a imagem oficial PHP com Apache
FROM php:8.2-apache

# Copie todos os arquivos do repositório para o diretório padrão do Apache
COPY . /var/www/html/

# Exponha a porta que o Render usa
EXPOSE 10000

# Inicialização do servidor Apache (já incluída na imagem)
CMD ["apache2-foreground"]
