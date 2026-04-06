#include <iostream>
#include <string>
using namespace std;


int main() {



    string username, passwd;

                    
    cout << "Welcome to the spend tracker" << endl;
    cout << "--------------------------------" << endl;
    cout << "Are you a new user? (y/n): ";
    char new_user;
    cin >> new_user;
    if (new_user == 'y') {
        cout << "New User Registration" << endl;
        cout << "--------------------------------" << endl;
        cout << "Enter your username: ";
        cin >> username;
        cout << "Enter your password: ";
        cin >> passwd;
    }
    else {
        cout << "Login" << endl;
        cout << "--------------------------------" << endl;
        cout << "Enter your username: ";
        cin >> username;
        cout << "Enter your password: ";
        cin >> passwd;
    }
    return 0;
}