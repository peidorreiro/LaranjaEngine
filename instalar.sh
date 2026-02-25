clear
echo "atualizando pacotes/updating packages..."
sleep 2
apt update && apt upgrade -y
sleep 2
clear
echo "instalando os pacotes necessários.../installing the required packages..."
sleep 2
clear
apt install tor nginx php php-gd ffmpeg
sleep 2
clear
echo "rodando uma sessão para você testar a engine no localhost porta 8082, se quiser apagar todos os seus posts digite \"rm b/uploads/message_board.db\" use nginx se for hospedar isso para outras pessoas./ running a testing session for you to test the engine, if you're going to host this for other people please use nginx instead! to delete all your posts run \"rm b/uploads/message_board.db\""
echo "para fechar essa sessão, aperte no ctrl e depois C, sua senha de administrador é \"admin\" mude para uma senha mais forte no mod.php na aba const ADMIN_PASSWORD/press ctrl + c to close this session, your admin password is \"admin\" change it to a more stronger password in b/mod.php on the const ADMIN_PASSWORD line."

php -S localhost:8082
