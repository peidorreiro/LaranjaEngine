clear
echo "atualizando pacotes..."
sleep 2
apt update && apt upgrade -y
sleep 2
clear
echo "instalando os pacotes necessários..."
sleep 2
clear
apt install tor nginx php php-gd ffmpeg
sleep 2
clear
echo "rodando uma sessão para você testar a engine no localhost porta 8082, se quiser apagar todos os seus posts digite \"rm b/uploads/message_board.db\ use nginx se for hospedar isso para outras pessoas."
echo "para fechar essa sessão, aperte no ctrl e depois C, sua senha de administrador é \"admin\" mude para uma senha mais forte no mod.php na aba const ADMIN_PASSWORD"

php -S localhost:8082
